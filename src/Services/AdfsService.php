<?php

namespace WaterlooBae\UwAdfs\Services;

use Exception;
use Illuminate\Support\Facades\Auth as LaravelAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;
use WaterlooBae\UwAdfs\Saml\SamlHandler;

/**
 * ADFS Service - Handles SAML integration
 * 
 * This service can work with either:
 * 1. Custom SAML Handler (default, no external dependencies)
 * 2. OneLogin SAML Library (optional, for backward compatibility)
 */
class AdfsService
{
    protected array $config;
    protected SamlHandler $samlHandler;
    protected ?object $oneLoginAuth = null;
    protected bool $useOneLogin = false;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->initializeSaml();
    }

    /**
     * Initialize SAML - use custom handler or OneLogin
     */
    protected function initializeSaml(): void
    {
        // Check if OneLogin is available and configured
        if ($this->shouldUseOneLogin()) {
            $this->useOneLogin = true;
            $this->initializeOneLogin();
        } else {
            // Use custom SAML handler
            $this->samlHandler = new SamlHandler($this->config);
        }
    }

    /**
     * Check if OneLogin should be used
     */
    protected function shouldUseOneLogin(): bool
    {
        // Check if OneLogin is available
        if (!class_exists('OneLogin\Saml2\Auth')) {
            return false;
        }

        // Check environment variable or config
        if (env('UW_ADFS_USE_ONELOGIN') === 'false') {
            return false;
        }

        return true;
    }

    /**
     * Initialize OneLogin (for backward compatibility)
     */
    protected function initializeOneLogin(): void
    {
        try {
            $samlConfig = $this->buildSamlConfig();
            $this->oneLoginAuth = new \OneLogin\Saml2\Auth($samlConfig);
            Log::info("Using OneLogin SAML library");
        } catch (Exception $e) {
            Log::warning("Failed to initialize OneLogin, falling back to custom SAML: " . $e->getMessage());
            $this->useOneLogin = false;
            $this->samlHandler = new SamlHandler($this->config);
        }
    }

    /**
     * Build SAML configuration array
     */
    public function buildSamlConfig(): array
    {
        $environment = $this->config['environment'];
        // Log::debug("Building SAML config for environment: {$environment}");
        $idpConfig = $this->config['idp'][$environment];

        // Log::debug("IdP EntityID: " . ($idpConfig['entityId'] ?? 'not set'));
        
        // Handle singleSignOnService which can be a string or array
        $ssoService = $idpConfig['singleSignOnService'] ?? 'not set';
        $ssoUrl = is_array($ssoService) ? ($ssoService['url'] ?? 'array-no-url') : $ssoService;
        // Log::debug("IdP SSO URL: {$ssoUrl}");

        // Load IdP certificate from XML metadata if available
        $metadataSource = $idpConfig['metadata_url'] ?? $idpConfig['metadata_file'] ?? 'auto';
        // Log::debug("Metadata source: {$metadataSource}");
        $x509cert = $this->extractCertificateFromMetadata($metadataSource);
        // Log::debug("Certificate loaded, length: " . strlen($x509cert) . " characters");
        
        $spEntityId = $this->config['sp']['entityId'] ?? 'not set';
        
        // Handle assertionConsumerService which can be a string or array
        $acsService = $this->config['sp']['assertionConsumerService'] ?? 'not set';
        $acsUrl = is_array($acsService) ? ($acsService['url'] ?? 'array-no-url') : $acsService;
        // Log::debug("SP EntityID: {$spEntityId}");
        // Log::debug("SP ACS URL: {$acsUrl}");

        return [
            'sp' => [
                'entityId' => $this->config['sp']['entityId'],
                'assertionConsumerService' => $this->config['sp']['assertionConsumerService'],
                'singleLogoutService' => $this->config['sp']['singleLogoutService'],
                'NameIDFormat' => $this->config['sp']['NameIDFormat'],
                'x509cert' => $this->config['sp']['x509cert'],
                'privateKey' => $this->config['sp']['privateKey'],
            ],
            'idp' => [
                'entityId' => $idpConfig['entityId'],
                'singleSignOnService' => $idpConfig['singleSignOnService'],
                'singleLogoutService' => $idpConfig['singleLogoutService'],
                'x509cert' => $x509cert,
            ],
            'security' => $this->config['security'],
        ];
    }

    /**
     * Extract X509 certificate from SAML metadata XML
     */
    protected function extractCertificateFromMetadata(string $metadataSource): string
    {
        try {
            $xml = $this->getMetadataXml($metadataSource);
            
            if (empty($xml)) {
                Log::warning("Metadata XML is empty for source: {$metadataSource}");
                return '';
            }
            
            // Log::debug("Metadata XML retrieved, size: " . strlen($xml) . " bytes");

            $doc = new \DOMDocument();
            if (!@$doc->loadXML($xml)) {
                Log::error("Failed to parse metadata XML");
                return '';
            }
            // Log::debug("Metadata XML parsed successfully");

            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
            
            $certNodes = $xpath->query('//ds:X509Certificate');
            // Log::debug("Found {$certNodes->length} X509Certificate node(s)");
            
            if ($certNodes->length > 0) {
                $cert = $certNodes->item(0)->nodeValue;
                Log::info("Successfully extracted X509 certificate (" . strlen($cert) . " chars)");
                return $cert;
            }
            
            // Log::warning("No X509Certificate nodes found in metadata");
            return '';
        } catch (\Exception $e) {
            Log::error("Error extracting certificate: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Get SAML metadata XML from URL or local file
     */
    protected function getMetadataXml(string $source): string
    {
        $environment = $this->config['environment'];
        $idpConfig = $this->config['idp'][$environment];
        $metadataConfig = $this->config['metadata'];

        // If source is 'auto', determine if we should use URL or file
        if ($source === 'auto') {
            if (isset($idpConfig['metadata_url'])) {
                return $this->fetchMetadataFromUrl($idpConfig['metadata_url'], $metadataConfig);
            } else {
                return $this->loadMetadataFromFile($idpConfig['metadata_file'] ?? '');
            }
        }

        // If source is a URL
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            return $this->fetchMetadataFromUrl($source, $metadataConfig);
        }

        // Otherwise treat as file path
        return $this->loadMetadataFromFile($source);
    }

    /**
     * Fetch metadata from remote URL with caching
     */
    protected function fetchMetadataFromUrl(string $url, array $config): string
    {
        $cacheKey = 'uw_adfs_metadata_' . md5($url);
        
        // Try to get from cache first
        if ($config['cache_enabled'] && cache()->has($cacheKey)) {
            return cache($cacheKey);
        }

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => (int) ($config['timeout'] ?? 30),
                    'user_agent' => 'UW-ADFS-Laravel-Package/1.0',
                    'follow_location' => true,
                    'max_redirects' => 3,
                ]
            ]);

            $xml = file_get_contents($url, false, $context);
            
            if ($xml === false) {
                throw new \Exception("Failed to fetch metadata from URL: {$url}");
            }

            // Validate that it's valid XML
            $doc = new \DOMDocument();
            if (!$doc->loadXML($xml)) {
                throw new \Exception("Invalid XML metadata received from URL: {$url}");
            }

            // Cache the result
            if ($config['cache_enabled']) {
                $cacheDuration = (int) ($config['cache_duration'] ?? 3600);
                cache([$cacheKey => $xml], now()->addSeconds($cacheDuration));
            }

            return $xml;

        } catch (\Exception $e) {
            Log::warning("Failed to fetch ADFS metadata from URL: {$url}. Error: " . $e->getMessage());
            
            // Fallback to local file if configured
            if ($config['fallback_to_local'] ?? true) {
                $environment = $this->config['environment'];
                $fallbackFile = $this->config['idp'][$environment]['metadata_file'] ?? '';
                
                if (!empty($fallbackFile)) {
                    Log::info("Falling back to local metadata file: {$fallbackFile}");
                    return $this->loadMetadataFromFile($fallbackFile);
                }
            }
            
            throw new \Exception("Failed to fetch metadata from URL and no fallback available: " . $e->getMessage());
        }
    }

    /**
     * Load metadata from local file
     */
    protected function loadMetadataFromFile(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \Exception("Metadata file not found: {$filePath}");
        }

        $xml = file_get_contents($filePath);
        
        if ($xml === false) {
            throw new \Exception("Failed to read metadata file: {$filePath}");
        }

        return $xml;
    }

    /**
     * Initiate SAML login
     */
    public function login(?string $returnTo = null): void
    {
        try {
            if ($this->useOneLogin) {
                $this->oneLoginAuth->login($returnTo);
            } else {
                $url = $this->samlHandler->buildAuthRequest($returnTo);
                header('Location: ' . $url);
            }
        } catch (Exception $e) {
            Log::error("Login initiation failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process SAML response (ACS endpoint)
     */
    public function acs(): array
    {
        Log::info("Processing SAML response");

        try {
            $samlResponse = $_POST['SAMLResponse'] ?? '';
            
            if (empty($samlResponse)) {
                throw new \Exception('Missing SAML response');
            }

            $responseData = $this->samlHandler->processSamlResponse($samlResponse);

            if (!$responseData['authenticated']) {
                throw new \Exception('SAML authentication failed');
            }

            Log::info("SAML authentication successful");

            return [
                'authenticated' => true,
                'attributes' => $responseData['attributes'],
                'nameId' => $responseData['nameId'],
                'nameIdFormat' => $responseData['nameIdFormat'] ?? '',
                'sessionIndex' => $responseData['sessionIndex'] ?? '',
            ];
        } catch (\Exception $e) {
            Log::error("SAML Response processing error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Initiate SAML logout and get the logout URL
     */
    public function logout(?string $returnTo = null, ?string $nameId = null, ?string $sessionIndex = null, ?string $nameIdFormat = null, ?string $email = null): string
    {
        try {
            Log::info("Initiating SAML logout");
            Log::info("Logout session data - NameID: " . ($nameId ? $nameId : 'NULL/EMPTY') . ", SessionIndex: " . ($sessionIndex ? $sessionIndex : 'NULL/EMPTY') . ", NameIDFormat: " . ($nameIdFormat ? $nameIdFormat : 'NULL/EMPTY') . ", Email: " . ($email ? $email : 'NULL/EMPTY'));
            
            $samlConfig = $this->buildSamlConfig();
            
            // Validate session data
            if (empty($nameId) || empty($sessionIndex)) {
                Log::error("Missing critical logout data - NameID: " . ($nameId ? 'provided' : 'NULL') . ", SessionIndex: " . ($sessionIndex ? 'provided' : 'NULL'));
                Log::warning("Falling back to simple logout");
                return $samlConfig['idp']['singleLogoutService']['url'] ?? '';
            }
            
            // Use the exact NameID format from the login session
            // CRITICAL: Must match what ADFS sent during login, otherwise logout will fail with MSIS7054
            if (empty($nameIdFormat)) {
                // If no format was stored, try to infer from NameID value
                // ADFS typically uses windows-domain-qualified-name for userids
                if (strpos($nameId, '@') === false && strpos($nameId, '\\') === false) {
                    // Plain userid without domain - likely windows-domain-qualified-name
                    $nameIdFormat = 'urn:oasis:names:tc:SAML:1.1:nameid-format:WindowsDomainQualifiedName';
                    Log::info("Inferring NameIDFormat as WindowsDomainQualifiedName based on NameID value");
                } else {
                    // Has @ or \ - let ADFS determine
                    $nameIdFormat = null;
                    Log::info("NameIDFormat not stored and cannot infer - will omit Format attribute");
                }
            }
            
            Log::info("Using NameID: {$nameId}" . ($nameIdFormat ? ", format: {$nameIdFormat}" : " (no format)"));
            
            // Set RelayState to SLS endpoint if not provided
            if (empty($returnTo)) {
                $samlConfig = $this->buildSamlConfig();
                $returnTo = $samlConfig['sp']['singleLogoutService']['url'] ?? config('app.url') . '/saml/sls';
                Log::info("Using default RelayState (SLS endpoint): {$returnTo}");
            }
            
            // Build logout request using custom SAML handler
            $logoutUrl = $this->samlHandler->buildLogoutRequest($nameId, $sessionIndex, $returnTo, $nameIdFormat);
            
            Log::info("Logout URL generated: " . substr($logoutUrl, 0, 100) . "...");
            return $logoutUrl;
            
        } catch (\Exception $e) {
            Log::error("Logout generation failed: " . $e->getMessage());
            Log::error("Exception trace: " . $e->getTraceAsString());
            
            // Fallback: return IdP SLS URL
            $samlConfig = $this->buildSamlConfig();
            $idpSls = $samlConfig['idp']['singleLogoutService']['url'] ?? '';
            return $idpSls;
        }
    }
    
    /**
     * Sign XML document with private key for SAML
     * 
     * This creates a digital signature to prove the LogoutRequest came from the authenticated SP.
     * ADFS requires signed logout requests when using HTTP-Redirect binding.
     */
    private function signXml(string $xml, string $privateKey, string $referenceId): string
    {
        try {
            // Log::debug("Starting XML signing process");
            
            // Load the XML document
            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = true;
            if (!$dom->loadXML($xml)) {
                throw new \Exception("Failed to load XML for signing");
            }
            
            // Parse the private key
            $keyResource = openssl_pkey_get_private($privateKey);
            if ($keyResource === false) {
                throw new \Exception("Failed to parse private key: " . openssl_error_string());
            }
            
            // Log::debug("Private key parsed successfully");
            
            $dsNamespace = 'http://www.w3.org/2000/09/xmldsig#';
            
            // Create SignedInfo element first to calculate signature
            $signedInfo = $dom->createElementNS($dsNamespace, 'ds:SignedInfo');
            
            // Add CanonicalizationMethod
            $canonicalization = $dom->createElementNS($dsNamespace, 'ds:CanonicalizationMethod');
            $canonicalization->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
            $signedInfo->appendChild($canonicalization);
            
            // Add SignatureMethod
            $signatureMethod = $dom->createElementNS($dsNamespace, 'ds:SignatureMethod');
            $signatureMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
            $signedInfo->appendChild($signatureMethod);
            
            // Add Reference
            $reference = $dom->createElementNS($dsNamespace, 'ds:Reference');
            $reference->setAttribute('URI', '#' . $referenceId);
            
            // Add Transforms
            $transforms = $dom->createElementNS($dsNamespace, 'ds:Transforms');
            
            $transform1 = $dom->createElementNS($dsNamespace, 'ds:Transform');
            $transform1->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
            $transforms->appendChild($transform1);
            
            $transform2 = $dom->createElementNS($dsNamespace, 'ds:Transform');
            $transform2->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
            $transforms->appendChild($transform2);
            
            $reference->appendChild($transforms);
            
            // Add DigestMethod
            $digestMethod = $dom->createElementNS($dsNamespace, 'ds:DigestMethod');
            $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
            $reference->appendChild($digestMethod);
            
            // Add DigestValue (placeholder, will be replaced with actual)
            $digestValue = $dom->createElementNS($dsNamespace, 'ds:DigestValue');
            $digestValue->appendChild($dom->createTextNode(''));
            $reference->appendChild($digestValue);
            
            $signedInfo->appendChild($reference);
            
            // Calculate digest of the original document element (before adding signature)
            $rootElement = $dom->documentElement;
            $rootXml = $dom->saveXML($rootElement);
            
            // Canonicalize and hash
            $digestData = $this->canonicalizeXml($rootXml);
            $digest = base64_encode(hash('sha256', $digestData, true));
            
            // Log::debug("Document digest calculated: " . substr($digest, 0, 20) . "...");
            
            // Update DigestValue with actual value
            $digestValue->firstChild->nodeValue = $digest;
            
            // Serialize SignedInfo for signature calculation
            $signedInfoXml = $dom->saveXML($signedInfo);
            $signedInfoCanonicalized = $this->canonicalizeXml($signedInfoXml);
            
            // Log::debug("SignedInfo canonicalized, length: " . strlen($signedInfoCanonicalized));
            
            // Sign the SignedInfo
            $signatureValue = '';
            $result = openssl_sign($signedInfoCanonicalized, $signatureValue, $keyResource, 'sha256WithRSAEncryption');
            
            if ($result === false) {
                throw new \Exception("Failed to sign XML: " . openssl_error_string());
            }
            
            // Log::debug("XML signed successfully, signature length: " . strlen($signatureValue));
            
            // Create Signature element
            $signatureElement = $dom->createElementNS($dsNamespace, 'ds:Signature');
            
            // Add the SignedInfo we just created
            $signatureElement->appendChild($signedInfo);
            
            // Add SignatureValue
            $signatureValueElement = $dom->createElementNS($dsNamespace, 'ds:SignatureValue');
            $signatureValueElement->appendChild($dom->createTextNode(base64_encode($signatureValue)));
            $signatureElement->appendChild($signatureValueElement);
            
            // Insert Signature after Issuer element (ADFS expects specific element order)
            // Order should be: Issuer, Signature, NameID, SessionIndex
            $rootElement = $signatureElement->ownerDocument->documentElement;
            $issuerElement = $rootElement->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer')->item(0);
            
            if ($issuerElement) {
                // Insert after Issuer
                if ($issuerElement->nextSibling) {
                    $rootElement->insertBefore($signatureElement, $issuerElement->nextSibling);
                    // Log::debug("Inserted Signature immediately after Issuer element");
                } else {
                    $rootElement->appendChild($signatureElement);
                    // Log::debug("Appended Signature at end (Issuer was last element)");
                }
            } else {
                Log::warning("Issuer element not found, appending Signature as last element");
                $rootElement->appendChild($signatureElement);
            }
            
            $signedXml = $dom->saveXML();
            // Log::debug("Signed XML length: " . strlen($signedXml));
            // Log complete signed XML for verification
            // Log::debug("Signed XML (COMPLETE): " . $signedXml);
            
            return $signedXml;
        } catch (\Exception $e) {
            Log::error("XML signing error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Canonicalize XML for signature calculation
     */
    private function canonicalizeXml(string $xml): string
    {
        // Remove extra whitespace and newlines for consistent canonicalization
        $xml = preg_replace('/>\s+</', '><', $xml);
        $xml = trim($xml);
        return $xml;
    }

    /**
     * Build and return signed LogoutResponse URL for HTTP-Redirect binding
     * 
     * This generates the complete LogoutResponse redirect URL that needs to be 
     * sent back to ADFS to complete the Single Logout flow.
     */
    public function buildLogoutResponseUrl(?string $relayState = null, ?string $inResponseTo = null): string
    {
        try {
            Log::info("Building LogoutResponse URL...");
            
            // Use custom SAML handler to build logout response
            $logoutResponseUrl = $this->samlHandler->buildLogoutResponse($inResponseTo, $relayState);
            
            Log::info("LogoutResponse URL: " . substr($logoutResponseUrl, 0, 200) . "...");
            return $logoutResponseUrl;
            
        } catch (\Exception $e) {
            Log::error("Error building LogoutResponse URL: " . $e->getMessage());
            Log::debug("Exception: " . $e->getTraceAsString());
            
            // Fallback to IdP SLS URL
            $samlConfig = $this->buildSamlConfig();
            $idpSls = $samlConfig['idp']['singleLogoutService']['url'] ?? '';
            return $idpSls;
        }
    }

    /**
     * Process SAML logout request from ADFS and generate signed LogoutResponse
     * 
     * This method:
     * 1. Validates the incoming LogoutRequest from ADFS
     * 2. Builds a signed LogoutResponse 
     * 3. Returns the URL to redirect to
     */
    public function sls(): string
    {
        Log::info("AdfsService::sls() - Processing incoming SAML LogoutRequest from ADFS");
        
        try {
            // Log the request details
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
            $hasLogoutRequest = isset($_REQUEST['SAMLRequest']) || isset($_REQUEST['SAMLResponse']);
            Log::info("SLS Request Method: {$requestMethod}, Has SAML Data: " . ($hasLogoutRequest ? 'yes' : 'no'));
            
            $samlRequest = $_REQUEST['SAMLRequest'] ?? null;
            $relayState = $_REQUEST['RelayState'] ?? '';
            
            if ($samlRequest) {
                Log::debug("Received SAMLRequest (first 100 chars): " . substr($samlRequest, 0, 100) . "...");
            }
            if ($relayState) {
                Log::debug("RelayState: " . $relayState);
            }
            
            if (empty($samlRequest)) {
                Log::error("No SAMLRequest in logout request");
                return '';
            }
            
            // Process the incoming LogoutRequest from ADFS
            Log::info("Processing incoming LogoutRequest...");
            $logoutData = $this->samlHandler->processLogoutRequest($samlRequest);
            
            Log::info("LogoutRequest processed successfully");
            Log::debug("Logout data: " . json_encode($logoutData));
            
            // Build the LogoutResponse URL
            $logoutResponseUrl = $this->buildLogoutResponseUrl($relayState, $logoutData['id'] ?? null);
            
            Log::info("LogoutResponse URL built successfully");
            return $logoutResponseUrl;
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            Log::error("AdfsService::sls() - Exception: " . $errorMessage);
            Log::debug("Exception trace: " . $e->getTraceAsString());
            return '';
        }
    }

    /**
     * Get SAML metadata
     */
    public function getMetadata(): Response
    {
        $metadata = $this->samlHandler->generateMetadata();

        return response($metadata, 200, [
            'Content-Type' => 'text/xml',
        ]);
    }

    /**
     * Map SAML attributes to user data
     */
    public function mapAttributes(array $attributes): array
    {
        $mapping = config('uw-adfs.attribute_mapping', []);
        $userData = [];

        foreach ($mapping as $userField => $samlAttribute) {
            // Handle multiple possible attribute names (array of alternatives)
            if (is_array($samlAttribute)) {
                foreach ($samlAttribute as $possibleAttribute) {
                    if (isset($attributes[$possibleAttribute]) && !empty($attributes[$possibleAttribute])) {
                        $value = $attributes[$possibleAttribute];
                        
                        // Special handling for groups - keep as array and clean up
                        if ($userField === 'groups') {
                            $userData[$userField] = $this->processGroupAttributes($value);
                        } else {
                            $userData[$userField] = is_array($value) ? $value[0] : $value;
                        }
                        break; // Use first matching attribute
                    }
                }
            } else {
                // Single attribute name
                if (isset($attributes[$samlAttribute]) && !empty($attributes[$samlAttribute])) {
                    $value = $attributes[$samlAttribute];
                    
                    // Special handling for groups
                    if ($userField === 'groups') {
                        $userData[$userField] = $this->processGroupAttributes($value);
                    } else {
                        $userData[$userField] = is_array($value) ? $value[0] : $value;
                    }
                }
            }
        }

        return $userData;
    }

    /**
     * Process group attributes to extract clean group names
     */
    protected function processGroupAttributes($groups): array
    {
        if (!is_array($groups)) {
            $groups = [$groups];
        }
        
        $cleanGroups = [];
        foreach ($groups as $group) {
            // Extract group name from Distinguished Name format
            // e.g., "CN=Faculty,OU=Groups,DC=uwaterloo,DC=ca" -> "Faculty"
            if (preg_match('/^CN=([^,]+),/', $group, $matches)) {
                $cleanGroups[] = $matches[1];
            } else {
                // If not DN format, use as-is
                $cleanGroups[] = $group;
            }
        }
        
        return array_unique($cleanGroups);
    }

    /**
     * Create or update user from SAML attributes
     */
    public function createOrUpdateUser(array $attributes): ?\Illuminate\Database\Eloquent\Model
    {
        $userData = $this->mapAttributes($attributes);
        
        if (empty($userData['email'])) {
            throw new \Exception('Email attribute is required for user creation');
        }

        $userModel = $this->config['user_model'];
        
        return $userModel::updateOrCreate(
            ['email' => $userData['email']],
            $userData
        );
    }

    /**
     * Get user groups from SAML attributes
     */
    public function getUserGroups(array $attributes): array
    {
        $groupsMapping = config('uw-adfs.attribute_mapping.groups', ['http://schemas.xmlsoap.org/claims/Group']);
        $groups = [];

        // Handle multiple possible group attribute names
        $possibleAttributes = is_array($groupsMapping) ? $groupsMapping : [$groupsMapping];
        
        foreach ($possibleAttributes as $groupsAttribute) {
            if (isset($attributes[$groupsAttribute]) && !empty($attributes[$groupsAttribute])) {
                $rawGroups = $attributes[$groupsAttribute];
                $groups = $this->processGroupAttributes($rawGroups);
                break; // Use first matching attribute
            }
        }

        return $groups;
    }

    /**
     * Check if user has required group
     */
    public function userHasGroup(array $attributes, string $requiredGroup): bool
    {
        $userGroups = $this->getUserGroups($attributes);
        return in_array($requiredGroup, $userGroups);
    }

    /**
     * Get SAML Handler instance
     */
    public function getSamlHandler(): SamlHandler
    {
        return $this->samlHandler;
    }
}