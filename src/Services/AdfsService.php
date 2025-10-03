<?php

namespace WaterlooBae\UwAdfs\Services;

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
        $idpConfig = $this->config['idp'][$environment];

        // Load IdP certificate from XML metadata if available
        $metadataSource = $idpConfig['metadata_url'] ?? $idpConfig['metadata_file'] ?? 'auto';
        $x509cert = $this->extractCertificateFromMetadata($metadataSource);

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
        $xml = $this->getMetadataXml($metadataSource);
        
        if (empty($xml)) {
            return '';
        }

        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        
        $certNodes = $xpath->query('//ds:X509Certificate');
        
        if ($certNodes->length > 0) {
            return $certNodes->item(0)->nodeValue;
        }

        return '';
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
    public function login(string $returnTo = null): void
    {
        $this->samlAuth->login($returnTo);
    }

    /**
     * Process SAML response (ACS endpoint)
     */
    public function acs(): array
    {
        $this->samlAuth->processResponse();

        $errors = $this->samlAuth->getErrors();
        if (!empty($errors)) {
            throw new \Exception('SAML Response error: ' . implode(', ', $errors));
        }

        if (!$this->samlAuth->isAuthenticated()) {
            throw new \Exception('SAML authentication failed');
        }

        return [
            'authenticated' => true,
            'attributes' => $this->samlAuth->getAttributes(),
            'nameId' => $this->samlAuth->getNameId(),
            'nameIdFormat' => $this->samlAuth->getNameIdFormat(),
            'sessionIndex' => $this->samlAuth->getSessionIndex(),
        ];
    }

    /**
     * Initiate SAML logout
     */
    public function logout(string $returnTo = null, string $nameId = null, string $sessionIndex = null): void
    {
        $this->samlAuth->logout($returnTo, [], $nameId, $sessionIndex);
    }

    /**
     * Process SAML logout response (SLS endpoint)
     */
    public function sls(): bool
    {
        $this->samlAuth->processSLO(true);

        $errors = $this->samlAuth->getErrors();
        if (!empty($errors)) {
            throw new \Exception('SAML SLO error: ' . implode(', ', $errors));
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