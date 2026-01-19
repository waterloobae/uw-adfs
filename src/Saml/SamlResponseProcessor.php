<?php

namespace WaterlooBae\UwAdfs\Saml;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Processes SAML Responses from the IdP
 * 
 * Handles parsing, validation, and attribute extraction from SAML assertions
 */
class SamlResponseProcessor
{
    protected array $config;
    protected XmlSignatureHandler $signatureHandler;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->signatureHandler = new XmlSignatureHandler();
    }

    /**
     * Process SAML Response XML
     * 
     * @param string $xml The SAML Response XML
     * @return array Contains attributes, nameId, sessionIndex, etc.
     * @throws Exception
     */
    public function process(string $xml): array
    {
        try {
            Log::debug("SAML Response XML received, length: " . strlen($xml));

            // Parse XML
            $dom = new \DOMDocument();
            if (!@$dom->loadXML($xml)) {
                throw new Exception("Invalid SAML Response XML");
            }

            // Validate response structure
            $this->validateResponseStructure($dom);

            // Extract response data
            $responseData = $this->extractResponseData($dom);

            // Verify signature if required
            if ($this->config['security']['wantAssertionsSigned'] ?? false) {
                $this->verifyAssertionSignature($dom);
            }

            // Validate timestamps and conditions
            $this->validateConditions($dom);

            // Validate InResponseTo if present
            $this->validateInResponseTo($dom);

            // Extract attributes
            $attributes = $this->extractAttributes($dom);

            Log::info("SAML Response processed successfully");
            Log::debug("Extracted " . count($attributes) . " attributes");

            return [
                'authenticated' => true,
                'attributes' => $attributes,
                'nameId' => $responseData['nameId'],
                'nameIdFormat' => $responseData['nameIdFormat'],
                'sessionIndex' => $responseData['sessionIndex'],
                'issuer' => $responseData['issuer'],
                'responseXml' => $xml,
            ];
        } catch (Exception $e) {
            Log::error("SAML Response processing failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate SAML Response structure
     */
    protected function validateResponseStructure(\DOMDocument $dom): void
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

        // Check for Response element
        $responseNodes = $xpath->query('//samlp:Response');
        if ($responseNodes->length === 0) {
            throw new Exception("No SAML Response element found");
        }

        // Check for Assertion element
        $assertionNodes = $xpath->query('//saml:Assertion');
        if ($assertionNodes->length === 0) {
            throw new Exception("No SAML Assertion found in Response");
        }

        Log::debug("SAML Response structure validated");
    }

    /**
     * Extract key data from response
     */
    protected function extractResponseData(\DOMDocument $dom): array
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

        // Extract NameID
        $nameIdNodes = $xpath->query('//saml:NameID');
        $nameId = null;
        $nameIdFormat = null;

        if ($nameIdNodes->length > 0) {
            $nameIdNode = $nameIdNodes->item(0);
            $nameId = $nameIdNode->nodeValue;
            $nameIdFormat = $nameIdNode->getAttribute('Format');
        }

        // Extract SessionIndex
        $sessionIndexNodes = $xpath->query('//saml:AuthnStatement/@SessionIndex');
        $sessionIndex = null;
        if ($sessionIndexNodes->length > 0) {
            $sessionIndex = $sessionIndexNodes->item(0)->nodeValue;
        }

        // Extract Issuer
        $issuerNodes = $xpath->query('//saml:Issuer');
        $issuer = null;
        if ($issuerNodes->length > 0) {
            $issuer = $issuerNodes->item(0)->nodeValue;
        }

        Log::debug("Response data extracted - NameID: " . ($nameId ?: 'N/A') .
                  ", SessionIndex: " . ($sessionIndex ? 'present' : 'N/A') .
                  ", Issuer: " . ($issuer ?: 'N/A'));

        return [
            'nameId' => $nameId,
            'nameIdFormat' => $nameIdFormat,
            'sessionIndex' => $sessionIndex,
            'issuer' => $issuer,
        ];
    }

    /**
     * Verify assertion signature
     */
    protected function verifyAssertionSignature(\DOMDocument $dom): void
    {
        try {
            Log::debug("Verifying assertion signature");

            $idpConfig = $this->config['idp'][$this->config['environment']];
            $x509cert = $idpConfig['x509cert'] ?? '';

            if (empty($x509cert)) {
                Log::warning("No IdP certificate available for signature verification");
                return;
            }

            // Get the Assertion element
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

            $assertions = $xpath->query('//saml:Assertion');
            if ($assertions->length === 0) {
                throw new Exception("No assertion found to verify");
            }

            $assertionXml = $dom->saveXML($assertions->item(0));

            // Verify signature
            if (!$this->signatureHandler->verify($assertionXml, $x509cert)) {
                throw new Exception("Assertion signature verification failed");
            }

            Log::info("Assertion signature verified successfully");
        } catch (Exception $e) {
            Log::error("Signature verification error: " . $e->getMessage());
            if ($this->config['security']['wantAssertionsSigned'] ?? false) {
                throw $e;
            }
        }
    }

    /**
     * Validate SAML conditions (NotBefore, NotOnOrAfter)
     */
    protected function validateConditions(\DOMDocument $dom): void
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

        $conditionNodes = $xpath->query('//saml:Conditions');
        if ($conditionNodes->length === 0) {
            return;
        }

        $conditionElement = $conditionNodes->item(0);
        $notBefore = $conditionElement->getAttribute('NotBefore');
        $notOnOrAfter = $conditionElement->getAttribute('NotOnOrAfter');

        $now = time();

        if (!empty($notBefore)) {
            $notBeforeTime = strtotime($notBefore);
            if ($now < $notBeforeTime) {
                throw new Exception("Assertion not yet valid (NotBefore: $notBefore)");
            }
        }

        if (!empty($notOnOrAfter)) {
            $notOnOrAfterTime = strtotime($notOnOrAfter);
            // Add 1 minute clock skew tolerance
            if ($now >= ($notOnOrAfterTime + 60)) {
                throw new Exception("Assertion has expired (NotOnOrAfter: $notOnOrAfter)");
            }
        }

        Log::debug("SAML Conditions validated - NotBefore: " . ($notBefore ?: 'N/A') .
                  ", NotOnOrAfter: " . ($notOnOrAfter ?: 'N/A'));
    }

    /**
     * Validate InResponseTo attribute
     */
    protected function validateInResponseTo(\DOMDocument $dom): void
    {
        // This would validate that the response is to a request we sent
        // For now, we'll just log if present
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');

        $responseNodes = $xpath->query('//samlp:Response');
        if ($responseNodes->length > 0) {
            $inResponseTo = $responseNodes->item(0)->getAttribute('InResponseTo');
            if (!empty($inResponseTo)) {
                Log::debug("Response InResponseTo: " . $inResponseTo);
            }
        }
    }

    /**
     * Extract attributes from SAML Assertion
     * 
     * @param \DOMDocument $dom
     * @return array Attributes array, keyed by attribute name
     */
    protected function extractAttributes(\DOMDocument $dom): array
    {
        $attributes = [];

        try {
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

            $attributeNodes = $xpath->query('//saml:Attribute');

            Log::debug("Found " . $attributeNodes->length . " attribute nodes");

            foreach ($attributeNodes as $attributeNode) {
                $attributeName = $attributeNode->getAttribute('Name');
                $attributeValues = [];

                // Get all AttributeValue elements
                $valueNodes = $attributeNode->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'AttributeValue');

                foreach ($valueNodes as $valueNode) {
                    $attributeValues[] = $valueNode->nodeValue;
                }

                // Store attribute - keep as array
                $attributes[$attributeName] = $attributeValues;

                Log::debug("Extracted attribute: $attributeName = " . implode(', ', $attributeValues));
            }
        } catch (Exception $e) {
            Log::warning("Error extracting attributes: " . $e->getMessage());
        }

        return $attributes;
    }
}
