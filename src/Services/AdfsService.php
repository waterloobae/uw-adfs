<?php

namespace WaterlooBae\UwAdfs\Services;

use Exception;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use Illuminate\Support\Facades\Auth as LaravelAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class AdfsService
{
    protected array $config;
    protected Auth $samlAuth;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->initializeSamlAuth();
    }

    /**
     * Initialize SAML Auth instance
     */
    protected function initializeSamlAuth(): void
    {
        $samlConfig = $this->buildSamlConfig();
        $this->samlAuth = new Auth($samlConfig);
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
        $this->samlAuth->login($returnTo);
    }

    /**
     * Process SAML response (ACS endpoint)
     */
    public function acs(): array
    {
        Log::info("Processing SAML response");
        // Log::debug("SAML Auth initialized, checking for response");
        
        $this->samlAuth->processResponse();
        // Log::debug("SAML response processed");

        $errors = $this->samlAuth->getErrors();
        if (!empty($errors)) {
            Log::error('SAML Response errors: ' . implode(', ', $errors));
            Log::error('SAML error reason: ' . $this->samlAuth->getLastErrorReason());
            // Log::error('Last response XML: ' . $this->samlAuth->getLastResponseXML());
            
            // Log detailed status information
            $responseXml = $this->samlAuth->getLastResponseXML();
            if (!empty($responseXml)) {
                // Log::debug("SAML Response XML (first 1000 chars): " . substr($responseXml, 0, 1000));
            }
            
            throw new \Exception('SAML Response error: ' . implode(', ', $errors));
        }

        // Log::debug("No SAML errors, checking authentication status");
        
        if (!$this->samlAuth->isAuthenticated()) {
            Log::error('User not authenticated after processing response');
            // Log::debug("SAML Auth session index: " . $this->samlAuth->getSessionIndex());
            // Log::debug("SAML nameId: " . $this->samlAuth->getNameId());
            throw new \Exception('SAML authentication failed');
        }

        Log::info("SAML authentication successful");
        // Log::debug("User nameId: " . $this->samlAuth->getNameId());
        // Log::debug("User attributes: " . json_encode($this->samlAuth->getAttributes(), JSON_PRETTY_PRINT));

        return [
            'authenticated' => true,
            'attributes' => $this->samlAuth->getAttributes(),
            'nameId' => $this->samlAuth->getNameId(),
            'nameIdFormat' => $this->samlAuth->getNameIdFormat(),
            'sessionIndex' => $this->samlAuth->getSessionIndex(),
        ];
    }

    /**
     * Initiate SAML logout and get the logout URL
     */
    public function logout(?string $returnTo = null, ?string $nameId = null, ?string $sessionIndex = null, ?string $nameIdFormat = null): string
    {
        try {
            Log::info("Initiating SAML logout via OneLogin");
            Log::info("Logout session data - NameID: {$nameId}, SessionIndex: {$sessionIndex}, NameIDFormat: {$nameIdFormat}");
            
            // Pass session data to OneLogin's logout method
            // This ensures the correct NameID and SessionIndex are included in the LogoutRequest
            $this->samlAuth->logout($returnTo, [], $nameId, $sessionIndex, false, $nameIdFormat);
            
            // Check if OneLogin set the Location header with logout URL
            $headers = headers_list();
            foreach ($headers as $header) {
                if (stripos($header, 'Location:') === 0) {
                    $logoutUrl = trim(substr($header, 9));
                    Log::info("Logout URL generated by OneLogin: " . substr($logoutUrl, 0, 100) . "...");
                    return $logoutUrl;
                }
            }
            
            // Fallback: return IdP SLS URL if OneLogin didn't generate one
            $samlConfig = $this->buildSamlConfig();
            $idpSls = $samlConfig['idp']['singleLogoutService']['url'] ?? '';
            
            if ($idpSls) {
                Log::warning("OneLogin did not generate logout URL, using IdP SLS: " . $idpSls);
                return $idpSls;
            }
            
            Log::error("Failed to generate logout URL - no IdP SLS configured");
            return '';
            
        } catch (\Exception $e) {
            Log::error("Logout generation failed: " . $e->getMessage());
            
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
     * Process SAML logout response (SLS endpoint)
     */
    public function sls(): bool
    {
        Log::info("AdfsService::sls() - Processing SAML Single Logout Response from ADFS");
        
        try {
            // Log the request details
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
            $hasLogoutRequest = isset($_REQUEST['SAMLRequest']) || isset($_REQUEST['SAMLResponse']);
            // Log::debug("SLS Request Method: {$requestMethod}, Has SAML Data: " . ($hasLogoutRequest ? 'yes' : 'no'));
            
            // Check if SAML session data exists
            if (session()->has('saml_session')) {
                $samlSession = session()->get('saml_session');
                // Log::debug("SAML session found. NameID: " . ($samlSession['nameId'] ?? 'N/A'));
            } else {
                // Log::debug("No SAML session found in request");
            }
            
            // Try to process SLO (Single Logout)
            // The false parameter allows processing logout without requiring strict validation
            $this->samlAuth->processSLO(false);
            
            // Log::info("AdfsService::sls() - processSLO() completed without exception");
            
        } catch (\Exception $e) {
            // Handle binding mismatch errors gracefully
            $errorMessage = $e->getMessage();
            
            Log::warning("AdfsService::sls() - Exception during processSLO: " . $errorMessage);
            // Log::debug("Exception trace: " . $e->getTraceAsString());
            
            // If it's a binding error, log it but allow logout to continue
            if (strpos($errorMessage, 'LogoutRequest/LogoutResponse not found') !== false || 
                strpos($errorMessage, 'HTTP_REDIRECT Binding') !== false) {
                Log::warning('SAML logout binding mismatch: ' . $errorMessage);
                // Return true to allow logout to complete despite binding issues
                return true;
            }
            
            // For other errors, throw the exception
            throw $e;
        }

        $errors = $this->samlAuth->getErrors();
        if (!empty($errors)) {
            // Log errors but allow logout to complete
            Log::warning('SAML SLO errors: ' . implode(', ', $errors));
        } else {
            // Log::info("AdfsService::sls() - No SAML errors reported");
        }
        
        // Check if we got a logout response
        $lastErrorReason = $this->samlAuth->getLastErrorReason();
        if ($lastErrorReason) {
            // Log::info("AdfsService::sls() - Last error reason: " . $lastErrorReason);
        }

        return true;
    }

    /**
     * Get SAML metadata
     */
    public function getMetadata(): Response
    {
        $samlConfig = $this->buildSamlConfig();
        $settings = new Settings($samlConfig);
        $metadata = $settings->getSPMetadata();
        $errors = $settings->validateMetadata($metadata);

        if (!empty($errors)) {
            throw new \Exception('Invalid SP metadata: ' . implode(', ', $errors));
        }

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
     * Get SAML Auth instance
     */
    public function getSamlAuth(): Auth
    {
        return $this->samlAuth;
    }
}