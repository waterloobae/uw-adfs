<?php

namespace WaterlooBae\UwAdfs\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;

class ProxyService
{
    protected array $config;
    protected AdfsService $adfsService;

    public function __construct(AdfsService $adfsService)
    {
        $this->config = config('uw-adfs.proxy', []);
        $this->adfsService = $adfsService;
    }

    /**
     * Check if proxy mode is enabled
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? false;
    }

    /**
     * Get proxy mode (server, client, both)
     */
    public function getMode(): string
    {
        return $this->config['mode'] ?? 'both';
    }

    /**
     * Handle incoming SAML request from downstream client
     */
    public function handleClientRequest(array $samlRequest): array
    {
        if (!$this->isEnabled() || !in_array($this->getMode(), ['server', 'both'])) {
            throw new \Exception('Proxy server mode not enabled');
        }

        // Validate the incoming request
        $this->validateClientRequest($samlRequest);

        // Store client context for later response
        $clientContext = [
            'entity_id' => $samlRequest['issuer'],
            'acs_url' => $samlRequest['acs_url'],
            'relay_state' => $samlRequest['relay_state'] ?? null,
            'request_id' => $samlRequest['id'],
            'timestamp' => time(),
        ];

        $sessionKey = 'proxy_client_' . $samlRequest['id'];
        Session::put($sessionKey, $clientContext);

        Log::info('UW ADFS Proxy: Received client request', [
            'client_entity_id' => $samlRequest['issuer'],
            'request_id' => $samlRequest['id']
        ]);

        return $clientContext;
    }

    /**
     * Forward authentication request to upstream ADFS
     */
    public function forwardToUpstream(array $clientContext): void
    {
        if (!$this->isEnabled() || !in_array($this->getMode(), ['client', 'both'])) {
            throw new \Exception('Proxy client mode not enabled');
        }

        // Build upstream SAML settings
        $upstreamSettings = $this->buildUpstreamSettings();
        $auth = new Auth($upstreamSettings);

        // Store proxy context in relay state
        $proxyRelayState = base64_encode(json_encode([
            'proxy' => true,
            'client_request_id' => $clientContext['request_id'],
            'original_relay_state' => $clientContext['relay_state'],
        ]));

        // Initiate login with upstream ADFS
        $auth->login($proxyRelayState);
        exit();
    }

    /**
     * Handle response from upstream ADFS and forward to client
     */
    public function handleUpstreamResponse(): array
    {
        if (!$this->isEnabled()) {
            throw new \Exception('Proxy mode not enabled');
        }

        // Process upstream SAML response
        $upstreamSettings = $this->buildUpstreamSettings();
        $auth = new Auth($upstreamSettings);
        $auth->processResponse();

        if (!$auth->isAuthenticated()) {
            $errors = $auth->getErrors();
            throw new \Exception('Upstream authentication failed: ' . implode(', ', $errors));
        }

        // Extract relay state to find original client context
        $relayState = $_POST['RelayState'] ?? '';
        $proxyContext = json_decode(base64_decode($relayState), true);

        if (!$proxyContext || !isset($proxyContext['proxy'])) {
            throw new \Exception('Invalid proxy relay state');
        }

        $clientRequestId = $proxyContext['client_request_id'];
        $sessionKey = 'proxy_client_' . $clientRequestId;
        $clientContext = Session::get($sessionKey);

        if (!$clientContext) {
            throw new \Exception('Client context not found for request: ' . $clientRequestId);
        }

        // Get user attributes from upstream response
        $attributes = $auth->getAttributes();
        $nameId = $auth->getNameId();
        $sessionIndex = $auth->getSessionIndex();

        // Apply access control
        $accessControl = new AccessControlService(config('uw-adfs.access_control', []));
        $accessResult = $accessControl->isUserAuthorized($attributes);

        if (!$accessResult['authorized']) {
            throw new \Exception('Access denied: ' . $accessResult['reason']);
        }

        // Filter attributes if configured
        if ($this->config['attribute_filtering'] ?? true) {
            $attributes = $this->filterAttributes($attributes);
        }

        Log::info('UW ADFS Proxy: Upstream authentication successful', [
            'name_id' => $nameId,
            'client_request_id' => $clientRequestId,
            'attributes_count' => count($attributes)
        ]);

        return [
            'client_context' => $clientContext,
            'saml_data' => [
                'nameId' => $nameId,
                'sessionIndex' => $sessionIndex,
                'attributes' => $attributes,
            ],
            'access_result' => $accessResult,
        ];
    }

    /**
     * Generate SAML response for downstream client
     */
    public function generateClientResponse(array $clientContext, array $samlData): string
    {
        if (!$this->isEnabled() || !in_array($this->getMode(), ['server', 'both'])) {
            throw new \Exception('Proxy server mode not enabled');
        }

        // Build SAML response for client
        $clientSettings = $this->buildClientSettings($clientContext);
        
        $responseXml = $this->buildSamlResponse(
            $clientContext,
            $samlData,
            $clientSettings
        );

        Log::info('UW ADFS Proxy: Generated client response', [
            'client_entity_id' => $clientContext['entity_id'],
            'request_id' => $clientContext['request_id']
        ]);

        return $responseXml;
    }

    /**
     * Send SAML response to client application
     */
    public function sendResponseToClient(string $responseXml, array $clientContext): void
    {
        $encodedResponse = base64_encode($responseXml);
        
        // Clean up session data
        $sessionKey = 'proxy_client_' . $clientContext['request_id'];
        Session::forget($sessionKey);

        // Build POST form to send response
        $acsUrl = $clientContext['acs_url'];
        $relayState = $clientContext['relay_state'] ?? '';

        $html = '<!DOCTYPE html>
<html>
<head>
    <title>SAML Proxy Response</title>
</head>
<body onload="document.forms[0].submit()">
    <form method="post" action="' . htmlspecialchars($acsUrl) . '">
        <input type="hidden" name="SAMLResponse" value="' . htmlspecialchars($encodedResponse) . '" />
        <input type="hidden" name="RelayState" value="' . htmlspecialchars($relayState) . '" />
        <noscript>
            <input type="submit" value="Continue" />
        </noscript>
    </form>
</body>
</html>';

        echo $html;
        exit();
    }

    /**
     * Build upstream ADFS settings
     */
    protected function buildUpstreamSettings(): array
    {
        $baseSettings = $this->adfsService->buildSamlConfig();
        $upstreamConfig = $this->config['upstream'] ?? [];

        // Override IdP settings for upstream
        $baseSettings['idp']['entityId'] = $upstreamConfig['idp_entity_id'] ?? $baseSettings['idp']['entityId'];
        $baseSettings['idp']['singleSignOnService']['url'] = $upstreamConfig['sso_url'] ?? $baseSettings['idp']['singleSignOnService']['url'];
        $baseSettings['idp']['singleLogoutService']['url'] = $upstreamConfig['sls_url'] ?? $baseSettings['idp']['singleLogoutService']['url'];

        // Modify SP settings for proxy operation
        $baseSettings['sp']['assertionConsumerService']['url'] = config('app.url') . '/saml/proxy/acs';
        $baseSettings['sp']['singleLogoutService']['url'] = config('app.url') . '/saml/proxy/sls';
        $baseSettings['sp']['entityId'] = config('app.url') . '/proxy';

        return $baseSettings;
    }

    /**
     * Build client-specific SAML settings
     */
    protected function buildClientSettings(array $clientContext): array
    {
        $baseSettings = $this->adfsService->buildSamlConfig();

        // Configure as IdP for the client
        $baseSettings['idp']['entityId'] = config('app.url') . '/proxy';
        $baseSettings['sp']['entityId'] = $clientContext['entity_id'];
        $baseSettings['sp']['assertionConsumerService']['url'] = $clientContext['acs_url'];

        return $baseSettings;
    }

    /**
     * Build SAML response XML
     */
    protected function buildSamlResponse(array $clientContext, array $samlData, array $settings): string
    {
        $issuer = config('app.url') . '/proxy';
        $destination = $clientContext['acs_url'];
        $inResponseTo = $clientContext['request_id'];
        $nameId = $samlData['nameId'];
        $attributes = $samlData['attributes'];

        // Generate unique IDs
        $responseId = '_' . Utils::generateUniqueID();
        $assertionId = '_' . Utils::generateUniqueID();
        $issueInstant = gmdate('Y-m-d\TH:i:s\Z');
        $notOnOrAfter = gmdate('Y-m-d\TH:i:s\Z', time() + ($this->config['session_lifetime'] ?? 3600));

        // Build attributes XML
        $attributesXml = '';
        foreach ($attributes as $name => $values) {
            if (!is_array($values)) {
                $values = [$values];
            }
            
            $attributesXml .= '<saml:Attribute Name="' . htmlspecialchars($name) . '">';
            foreach ($values as $value) {
                $attributesXml .= '<saml:AttributeValue xsi:type="xs:string">' . htmlspecialchars($value) . '</saml:AttributeValue>';
            }
            $attributesXml .= '</saml:Attribute>';
        }

        $responseXml = '<?xml version="1.0" encoding="UTF-8"?>
<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" 
                xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                xmlns:xs="http://www.w3.org/2001/XMLSchema"
                ID="' . $responseId . '"
                Version="2.0"
                IssueInstant="' . $issueInstant . '"
                Destination="' . htmlspecialchars($destination) . '"
                InResponseTo="' . htmlspecialchars($inResponseTo) . '">
    <saml:Issuer>' . htmlspecialchars($issuer) . '</saml:Issuer>
    <samlp:Status>
        <samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/>
    </samlp:Status>
    <saml:Assertion ID="' . $assertionId . '"
                    Version="2.0"
                    IssueInstant="' . $issueInstant . '">
        <saml:Issuer>' . htmlspecialchars($issuer) . '</saml:Issuer>
        <saml:Subject>
            <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified">' . htmlspecialchars($nameId) . '</saml:NameID>
            <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
                <saml:SubjectConfirmationData NotOnOrAfter="' . $notOnOrAfter . '"
                                            Recipient="' . htmlspecialchars($destination) . '"
                                            InResponseTo="' . htmlspecialchars($inResponseTo) . '"/>
            </saml:SubjectConfirmation>
        </saml:Subject>
        <saml:Conditions NotBefore="' . gmdate('Y-m-d\TH:i:s\Z', time() - 60) . '"
                        NotOnOrAfter="' . $notOnOrAfter . '">
            <saml:AudienceRestriction>
                <saml:Audience>' . htmlspecialchars($clientContext['entity_id']) . '</saml:Audience>
            </saml:AudienceRestriction>
        </saml:Conditions>
        <saml:AuthnStatement AuthnInstant="' . $issueInstant . '">
            <saml:AuthnContext>
                <saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef>
            </saml:AuthnContext>
        </saml:AuthnStatement>
        <saml:AttributeStatement>
            ' . $attributesXml . '
        </saml:AttributeStatement>
    </saml:Assertion>
</samlp:Response>';

        return $responseXml;
    }

    /**
     * Validate incoming client request
     */
    protected function validateClientRequest(array $samlRequest): void
    {
        if (empty($samlRequest['issuer'])) {
            throw new \Exception('Missing issuer in client request');
        }

        if (empty($samlRequest['acs_url'])) {
            throw new \Exception('Missing ACS URL in client request');
        }

        if (empty($samlRequest['id'])) {
            throw new \Exception('Missing request ID in client request');
        }

        // Validate client is allowed (if configured)
        $allowedClients = $this->config['clients'] ?? [];
        if (!empty($allowedClients) && !isset($allowedClients[$samlRequest['issuer']])) {
            throw new \Exception('Client not authorized: ' . $samlRequest['issuer']);
        }
    }

    /**
     * Filter attributes based on configuration
     */
    protected function filterAttributes(array $attributes): array
    {
        // Get allowed attributes from configuration
        $allowedAttributes = array_values(config('uw-adfs.attribute_mapping', []));
        
        // Flatten array values if needed
        $flattenedAllowed = [];
        foreach ($allowedAttributes as $attr) {
            if (is_array($attr)) {
                $flattenedAllowed = array_merge($flattenedAllowed, $attr);
            } else {
                $flattenedAllowed[] = $attr;
            }
        }

        // Filter attributes
        $filtered = [];
        foreach ($attributes as $name => $values) {
            if (in_array($name, $flattenedAllowed)) {
                $filtered[$name] = $values;
            }
        }

        return $filtered;
    }

    /**
     * Get proxy metadata for client applications
     */
    public function getProxyMetadata(): string
    {
        if (!$this->isEnabled() || !in_array($this->getMode(), ['server', 'both'])) {
            throw new \Exception('Proxy server mode not enabled');
        }

        $entityId = config('app.url') . '/proxy';
        $ssoUrl = config('app.url') . '/saml/proxy/sso';
        $slsUrl = config('app.url') . '/saml/proxy/sls';

        // Get certificate if available
        $certificate = config('uw-adfs.sp.x509cert', '');
        $certXml = '';
        if (!empty($certificate)) {
            $certXml = '<KeyDescriptor use="signing">
                <ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
                    <ds:X509Data>
                        <ds:X509Certificate>' . htmlspecialchars($certificate) . '</ds:X509Certificate>
                    </ds:X509Data>
                </ds:KeyInfo>
            </KeyDescriptor>';
        }

        $metadata = '<?xml version="1.0" encoding="UTF-8"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"
                     entityID="' . htmlspecialchars($entityId) . '">
    <md:IDPSSODescriptor WantAuthnRequestsSigned="false"
                         protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
        ' . $certXml . '
        <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
                                Location="' . htmlspecialchars($ssoUrl) . '"/>
        <md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
                                Location="' . htmlspecialchars($slsUrl) . '"/>
    </md:IDPSSODescriptor>
</md:EntityDescriptor>';

        return $metadata;
    }
}