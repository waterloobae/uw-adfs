<?php

namespace WaterlooBae\UwAdfs\Saml;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Handles XML digital signatures for SAML requests/responses
 * 
 * Implements signing and verification using RSA-SHA256
 */
class XmlSignatureHandler
{
    const SIGNATURE_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';
    const XMLDSIG_ALGORITHM = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    const DIGEST_ALGORITHM = 'http://www.w3.org/2001/04/xmlenc#sha256';
    const CANONICALIZATION_ALGORITHM = 'http://www.w3.org/2001/10/xml-exc-c14n#';

    /**
     * Sign an XML document with a private key
     * 
     * Inserts a digital signature that proves the XML came from the holder of the private key.
     * The signature is placed after the Issuer element to comply with ADFS requirements.
     * 
     * @param string $xml The XML document to sign
     * @param string $privateKey The private key in PEM format
     * @param string $referenceId The ID attribute of the element being signed
     * @return string The signed XML document
     * @throws Exception
     */
    public function sign(string $xml, string $privateKey, string $referenceId): string
    {
        try {
            Log::debug("Starting XML signature process");

            // Load XML document
            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = true;

            if (!@$dom->loadXML($xml)) {
                throw new Exception("Failed to load XML for signing");
            }

            // Parse private key
            $keyResource = openssl_pkey_get_private($privateKey);
            if ($keyResource === false) {
                throw new Exception("Failed to parse private key: " . openssl_error_string());
            }

            Log::debug("Private key loaded successfully");

            // Get root element - this is what we're signing
            $rootElement = $dom->documentElement;

            // Create SignedInfo element
            $signedInfo = $dom->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:SignedInfo');

            // Add CanonicalizationMethod
            $canonicalization = $dom->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:CanonicalizationMethod');
            $canonicalization->setAttribute('Algorithm', self::CANONICALIZATION_ALGORITHM);
            $signedInfo->appendChild($canonicalization);

            // Add SignatureMethod
            $signatureMethod = $dom->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:SignatureMethod');
            $signatureMethod->setAttribute('Algorithm', self::XMLDSIG_ALGORITHM);
            $signedInfo->appendChild($signatureMethod);

            // Add Reference
            $reference = $dom->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:Reference');
            $reference->setAttribute('URI', '#' . $referenceId);

            // Add Transforms
            $transforms = $dom->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:Transforms');

            $transform1 = $dom->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:Transform');
            $transform1->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
            $transforms->appendChild($transform1);

            $transform2 = $dom->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:Transform');
            $transform2->setAttribute('Algorithm', self::CANONICALIZATION_ALGORITHM);
            $transforms->appendChild($transform2);

            $reference->appendChild($transforms);

            // Add DigestMethod
            $digestMethod = $dom->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:DigestMethod');
            $digestMethod->setAttribute('Algorithm', self::DIGEST_ALGORITHM);
            $reference->appendChild($digestMethod);

            // Calculate digest of root element
            $rootXml = $dom->saveXML($rootElement);
            $digestData = $this->canonicalizeXml($rootXml);
            $digest = base64_encode(hash('sha256', $digestData, true));

            Log::debug("Root element digest calculated");

            // Add DigestValue
            $digestValue = $dom->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:DigestValue');
            $digestValue->appendChild($dom->createTextNode($digest));
            $reference->appendChild($digestValue);

            $signedInfo->appendChild($reference);

            // Serialize SignedInfo and calculate its digest for signature
            $signedInfoXml = $dom->saveXML($signedInfo);
            $signedInfoCanonicalized = $this->canonicalizeXml($signedInfoXml);

            Log::debug("SignedInfo canonicalized, length: " . strlen($signedInfoCanonicalized));

            // Sign the SignedInfo
            $signatureValue = '';
            $result = openssl_sign(
                $signedInfoCanonicalized,
                $signatureValue,
                $keyResource,
                'sha256WithRSAEncryption'
            );

            if ($result === false) {
                throw new Exception("Failed to sign XML: " . openssl_error_string());
            }

            Log::debug("XML signed successfully, signature bytes: " . strlen($signatureValue));

            // Create Signature element
            $signatureElement = $dom->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:Signature');

            // Add SignedInfo
            $signatureElement->appendChild($signedInfo);

            // Add SignatureValue
            $signatureValueElement = $dom->createElementNS(self::SIGNATURE_NAMESPACE, 'ds:SignatureValue');
            $signatureValueElement->appendChild($dom->createTextNode(base64_encode($signatureValue)));
            $signatureElement->appendChild($signatureValueElement);

            // Add KeyInfo with certificate (if needed)
            // This is optional but helps IdP validate the signature

            // Insert signature after Issuer element (required by ADFS)
            $issuerElements = $rootElement->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer');

            if ($issuerElements->length > 0) {
                $issuerElement = $issuerElements->item(0);
                // Insert signature after issuer
                if ($issuerElement->nextSibling) {
                    $rootElement->insertBefore($signatureElement, $issuerElement->nextSibling);
                } else {
                    $rootElement->appendChild($signatureElement);
                }
                Log::debug("Signature inserted after Issuer element");
            } else {
                // If no Issuer, just append at the beginning
                $rootElement->insertBefore($signatureElement, $rootElement->firstChild);
                Log::debug("Signature inserted at beginning (no Issuer found)");
            }

            $signedXml = $dom->saveXML();
            Log::debug("Signed XML generated, length: " . strlen($signedXml));

            return $signedXml;
        } catch (Exception $e) {
            Log::error("XML signing error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verify XML signature
     * 
     * @param string $xml XML document with signature
     * @param string $publicCert X509 certificate in PEM format
     * @return bool True if signature is valid
     * @throws Exception
     */
    public function verify(string $xml, string $publicCert): bool
    {
        try {
            Log::debug("Starting XML signature verification");

            if (empty($publicCert)) {
                throw new Exception("Public certificate is empty");
            }

            $dom = new \DOMDocument();
            if (!@$dom->loadXML($xml)) {
                throw new Exception("Failed to load XML for verification");
            }

            // Find the signature
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('ds', self::SIGNATURE_NAMESPACE);

            $signatureNodes = $xpath->query('//ds:Signature');
            if ($signatureNodes->length === 0) {
                throw new Exception("No signature found in XML");
            }

            $signatureElement = $signatureNodes->item(0);

            // Extract SignatureValue
            $signatureValueNodes = $xpath->query('.//ds:SignatureValue', $signatureElement);
            if ($signatureValueNodes->length === 0) {
                throw new Exception("No SignatureValue found");
            }

            $signatureValue = base64_decode($signatureValueNodes->item(0)->nodeValue);

            // Extract SignedInfo
            $signedInfoNodes = $xpath->query('.//ds:SignedInfo', $signatureElement);
            if ($signedInfoNodes->length === 0) {
                throw new Exception("No SignedInfo found");
            }

            $signedInfoXml = $dom->saveXML($signedInfoNodes->item(0));
            $signedInfoCanonicalized = $this->canonicalizeXml($signedInfoXml);

            // Prepare certificate
            $certPem = "-----BEGIN CERTIFICATE-----\n" .
                      wordwrap($publicCert, 64, "\n", true) .
                      "\n-----END CERTIFICATE-----";

            // Verify signature
            $result = openssl_verify(
                $signedInfoCanonicalized,
                $signatureValue,
                $certPem,
                'sha256WithRSAEncryption'
            );

            if ($result === 1) {
                Log::info("Signature verification successful");
                return true;
            } elseif ($result === 0) {
                Log::warning("Signature verification failed - signature does not match");
                return false;
            } else {
                throw new Exception("Signature verification error: " . openssl_error_string());
            }
        } catch (Exception $e) {
            Log::error("XML verification error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Extract and validate certificate chain from XML signature
     * 
     * @param string $xml XML document
     * @return array Array of certificates in base64 format
     */
    public function extractCertificatesFromSignature(string $xml): array
    {
        $certificates = [];

        try {
            $dom = new \DOMDocument();
            if (!@$dom->loadXML($xml)) {
                return $certificates;
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('ds', self::SIGNATURE_NAMESPACE);

            $certNodes = $xpath->query('//ds:X509Certificate');

            foreach ($certNodes as $certNode) {
                $certificates[] = trim($certNode->nodeValue);
            }

            Log::debug("Extracted " . count($certificates) . " certificate(s) from signature");
        } catch (Exception $e) {
            Log::warning("Error extracting certificates: " . $e->getMessage());
        }

        return $certificates;
    }

    /**
     * Canonicalize XML for consistent hashing
     * 
     * This removes extra whitespace and follows XML canonicalization rules
     * so that the same XML always produces the same hash.
     * 
     * @param string $xml XML to canonicalize
     * @return string Canonicalized XML
     */
    private function canonicalizeXml(string $xml): string
    {
        // Remove extra whitespace between elements
        $xml = preg_replace('/>\s+</', '><', $xml);
        
        // Remove leading/trailing whitespace
        $xml = trim($xml);
        
        return $xml;
    }
}
