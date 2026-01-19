<?php

namespace WaterlooBae\UwAdfs\Saml;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Custom SAML Handler - Independent implementation without OneLogin
 * 
 * Handles SAML 2.0 protocol operations including:
 * - SAML Response processing (ACS)
 * - SAML Logout request/response handling (SLS)
 * - XML digital signatures
 * - Metadata generation
 */
class SamlHandler
{
    protected array $config;
    protected XmlSignatureHandler $signatureHandler;
    protected SamlResponseProcessor $responseProcessor;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->signatureHandler = new XmlSignatureHandler();
        $this->responseProcessor = new SamlResponseProcessor($config);
    }

    /**
     * Process SAML Response from IdP (ACS endpoint)
     * 
     * @param string $samlResponse Base64-encoded SAML Response from POST parameter
     * @return array Contains authenticated flag, attributes, nameId, sessionIndex
     * @throws Exception
     */
    public function processSamlResponse(string $samlResponse): array
    {
        try {
            Log::info("Processing SAML Response");

            // Decode base64 response
            $xml = base64_decode($samlResponse, true);
            if ($xml === false) {
                throw new Exception("Failed to decode SAML Response");
            }

            // Parse and validate the response
            return $this->responseProcessor->process($xml);
        } catch (Exception $e) {
            Log::error("SAML Response processing error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Build SAML AuthnRequest for login
     * 
     * @param string|null $returnTo URL to return to after successful authentication
     * @return string SAML Request encoded for HTTP-Redirect binding
     */
    public function buildAuthRequest(?string $returnTo = null): string
    {
        try {
            Log::info("Building SAML AuthnRequest");

            $spConfig = $this->config['sp'];
            $idpConfig = $this->config['idp'][$this->config['environment']];

            // Create AuthnRequest XML
            $requestId = $this->generateId();
            $issueInstant = $this->getIssueInstant();

            // Build NameIDPolicy - omit Format attribute if unspecified to let ADFS choose
            $nameIdFormat = $spConfig['NameIDFormat'];
            $nameIdPolicyXml = '';
            
            if (strpos($nameIdFormat, 'unspecified') !== false) {
                // For unspecified, let ADFS determine the format
                $nameIdPolicyXml = '<samlp:NameIDPolicy AllowCreate="true"/>';
            } else {
                // For specific formats, include the Format attribute
                $nameIdPolicyXml = '<samlp:NameIDPolicy Format="' . $nameIdFormat . '" AllowCreate="true"/>';
            }

            $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<samlp:AuthnRequest
    xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    ID="$requestId"
    Version="2.0"
    IssueInstant="$issueInstant"
    Destination="{$idpConfig['singleSignOnService']['url']}"
    AssertionConsumerServiceURL="{$spConfig['assertionConsumerService']['url']}"
    ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
    ForceAuthn="true">
    <saml:Issuer>{$spConfig['entityId']}</saml:Issuer>
    $nameIdPolicyXml
</samlp:AuthnRequest>
XML;

            // Sign if required
            if ($this->config['security']['authnRequestsSigned'] ?? false) {
                $xml = $this->signatureHandler->sign(
                    $xml,
                    $this->config['sp']['privateKey'],
                    $requestId
                );
            }

            // Encode for HTTP-Redirect binding (deflate + base64 + urlencode)
            $deflated = gzdeflate($xml);
            $encoded = base64_encode($deflated);

            $url = $idpConfig['singleSignOnService']['url'] . "?SAMLRequest=" . urlencode($encoded);

            if ($returnTo) {
                $url .= "&RelayState=" . urlencode($returnTo);
            }

            Log::debug("AuthnRequest URL built: " . substr($url, 0, 100) . "...");

            return $url;
        } catch (Exception $e) {
            Log::error("AuthnRequest build error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Build SAML LogoutRequest for initiating logout
     * 
     * @param string|null $nameId NameID to logout
     * @param string|null $sessionIndex Session index from SAML response
     * @param string|null $nameIdFormat NameID format
     * @return string SAML LogoutRequest encoded for HTTP-Redirect binding
     */
    public function buildLogoutRequest(
        ?string $nameId = null,
        ?string $sessionIndex = null,
        ?string $returnTo = null,
        ?string $nameIdFormat = null
    ): string {
        try {
            Log::info("Building SAML LogoutRequest");

            $spConfig = $this->config['sp'];
            $idpConfig = $this->config['idp'][$this->config['environment']];

            if (!$nameId || !$sessionIndex) {
                throw new Exception("NameID and SessionIndex required for logout");
            }

            $requestId = $this->generateId();
            $issueInstant = $this->getIssueInstant();
            
            // Build NameID element with or without Format attribute
            // CRITICAL: Format must match exactly what ADFS sent during login
            // Note: SPNameQualifier is omitted as some ADFS configurations reject it (MSIS7054)
            $nameIdXml = '<saml:NameID';
            if ($nameIdFormat) {
                $nameIdXml .= ' Format="' . htmlspecialchars($nameIdFormat, ENT_XML1, 'UTF-8') . '"';
            }
            $nameIdXml .= '>';
            $nameIdXml .= htmlspecialchars($nameId, ENT_XML1, 'UTF-8');
            $nameIdXml .= '</saml:NameID>';

            $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<samlp:LogoutRequest
    xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    ID="$requestId"
    Version="2.0"
    IssueInstant="$issueInstant"
    Destination="{$idpConfig['singleLogoutService']['url']}">
    <saml:Issuer>{$spConfig['entityId']}</saml:Issuer>
    $nameIdXml
    <samlp:SessionIndex>$sessionIndex</samlp:SessionIndex>
</samlp:LogoutRequest>
XML;

            Log::debug("Unsigned LogoutRequest XML: " . $xml);

            // Sign the request only if configured
            $shouldSign = $this->config['security']['logoutRequestSigned'] ?? false;
            Log::info("LogoutRequest signing config: " . ($shouldSign ? 'ENABLED' : 'DISABLED'));
            
            if ($shouldSign) {
                $xml = $this->signatureHandler->sign(
                    $xml,
                    $this->config['sp']['privateKey'],
                    $requestId
                );
                Log::debug("Signed LogoutRequest XML (first 500 chars): " . substr($xml, 0, 500));
            } else {
                Log::info("LogoutRequest left UNSIGNED per config");
            }

            // Encode for HTTP-Redirect binding (deflate + base64 + urlencode)
            $deflated = gzdeflate($xml);
            $encoded = base64_encode($deflated);

            $url = $idpConfig['singleLogoutService']['url'] . "?SAMLRequest=" . urlencode($encoded);
            
            // Add RelayState if provided
            if ($returnTo) {
                $url .= "&RelayState=" . urlencode($returnTo);
                Log::debug("Added RelayState: " . $returnTo);
            }

            Log::debug("LogoutRequest URL built: " . substr($url, 0, 100) . "...");

            return $url;
        } catch (Exception $e) {
            Log::error("LogoutRequest build error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process incoming SAML LogoutRequest from IdP
     * 
     * @param string $samlRequest Base64-encoded SAML LogoutRequest
     * @return array Contains RelayState and session info
     * @throws Exception
     */
    public function processLogoutRequest(string $samlRequest): array
    {
        try {
            Log::info("Processing SAML LogoutRequest from IdP");

            // Decode - could be gzip compressed
            $xml = base64_decode($samlRequest, true);
            if ($xml === false) {
                throw new Exception("Failed to decode LogoutRequest");
            }

            // Try to decompress if deflated
            $decompressed = @gzinflate($xml);
            if ($decompressed !== false) {
                $xml = $decompressed;
            }

            // Parse XML
            $dom = new \DOMDocument();
            if (!@$dom->loadXML($xml)) {
                throw new Exception("Invalid XML in LogoutRequest");
            }

            // Extract NameID and SessionIndex for logging
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
            $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');

            $nameIdNodes = $xpath->query('//saml:NameID');
            $sessionIndexNodes = $xpath->query('//samlp:SessionIndex');

            $nameId = $nameIdNodes->length > 0 ? $nameIdNodes->item(0)->nodeValue : null;
            $sessionIndex = $sessionIndexNodes->length > 0 ? $sessionIndexNodes->item(0)->nodeValue : null;

            Log::info("LogoutRequest parsed - NameID: " . ($nameId ?: 'N/A') . 
                     ", SessionIndex: " . ($sessionIndex ? substr($sessionIndex, 0, 20) . "..." : 'N/A'));

            return [
                'nameId' => $nameId,
                'sessionIndex' => $sessionIndex,
                'xml' => $xml,
            ];
        } catch (Exception $e) {
            Log::error("LogoutRequest processing error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Build SAML LogoutResponse for HTTP-Redirect binding
     * 
     * @param string|null $inResponseTo ID of the LogoutRequest being responded to
     * @param string|null $relayState RelayState to include in response
     * @return string SAML LogoutResponse URL for HTTP-Redirect binding
     */
    public function buildLogoutResponse(?string $inResponseTo = null, ?string $relayState = null): string
    {
        try {
            Log::info("Building SAML LogoutResponse");

            $spConfig = $this->config['sp'];
            $idpConfig = $this->config['idp'][$this->config['environment']];

            $responseId = $this->generateId();
            $issueInstant = $this->getIssueInstant();

            $inResponseToAttr = $inResponseTo ? "InResponseTo=\"$inResponseTo\"" : '';

            $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<samlp:LogoutResponse
    xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    ID="$responseId"
    Version="2.0"
    IssueInstant="$issueInstant"
    Destination="{$idpConfig['singleLogoutService']['url']}"
    $inResponseToAttr>
    <saml:Issuer>{$spConfig['entityId']}</saml:Issuer>
    <samlp:Status>
        <samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/>
    </samlp:Status>
</samlp:LogoutResponse>
XML;

            // Sign if required
            if ($this->config['security']['logoutResponseSigned'] ?? true) {
                $xml = $this->signatureHandler->sign(
                    $xml,
                    $this->config['sp']['privateKey'],
                    $responseId
                );
            }

            // Encode for HTTP-Redirect binding (deflate + base64 + urlencode)
            $deflated = gzdeflate($xml);
            $encoded = base64_encode($deflated);

            $url = $idpConfig['singleLogoutService']['url'] . "?SAMLResponse=" . urlencode($encoded);

            if ($relayState) {
                $url .= "&RelayState=" . urlencode($relayState);
            }

            Log::info("LogoutResponse URL built: " . substr($url, 0, 100) . "...");

            return $url;
        } catch (Exception $e) {
            Log::error("LogoutResponse build error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generate SAML metadata XML
     * 
     * @return string XML metadata
     */
    public function generateMetadata(): string
    {
        $builder = new MetadataBuilder($this->config);
        return $builder->generate();
    }

    /**
     * Generate unique ID for SAML requests/responses
     */
    public function generateId(): string
    {
        return '_' . bin2hex(random_bytes(21));
    }

    /**
     * Get current timestamp in SAML format (ISO 8601)
     */
    public function getIssueInstant(): string
    {
        return date('Y-m-d\TH:i:s\Z', time());
    }

    /**
     * Get XML signature handler
     */
    public function getSignatureHandler(): XmlSignatureHandler
    {
        return $this->signatureHandler;
    }

    /**
     * Get response processor
     */
    public function getResponseProcessor(): SamlResponseProcessor
    {
        return $this->responseProcessor;
    }
}
