<?php

namespace WaterlooBae\UwAdfs\Saml;

use Illuminate\Support\Facades\Log;

/**
 * Generates SAML SP metadata XML
 */
class MetadataBuilder
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Generate SAML metadata XML
     * 
     * @return string XML metadata
     */
    public function generate(): string
    {
        $spConfig = $this->config['sp'];
        $entityId = $spConfig['entityId'];
        $acsUrl = is_array($spConfig['assertionConsumerService']) 
            ? $spConfig['assertionConsumerService']['url'] 
            : $spConfig['assertionConsumerService'];
        $slsUrl = is_array($spConfig['singleLogoutService']) 
            ? $spConfig['singleLogoutService']['url'] 
            : $spConfig['singleLogoutService'];
        $nameIdFormat = $spConfig['NameIDFormat'] ?? 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified';

        $issueInstant = $this->getIssueInstant();

        $metadata = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<EntityDescriptor
    xmlns="urn:oasis:names:tc:SAML:2.0:metadata"
    xmlns:ds="http://www.w3.org/2000/09/xmldsig#"
    entityID="$entityId"
    ID="_$entityId"
    validUntil="2099-12-31T23:59:59Z">
    <SPSSODescriptor
        AuthnRequestsSigned="false"
        WantAssertionsSigned="false"
        protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
        <NameIDFormat>$nameIdFormat</NameIDFormat>
        <AssertionConsumerService
            Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
            Location="$acsUrl"
            index="0"
            isDefault="true"/>
        <SingleLogoutService
            Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
            Location="$slsUrl"
            ResponseLocation="$slsUrl"/>
XML;

        // Add certificate if available
        if (!empty($spConfig['x509cert'])) {
            $cert = $spConfig['x509cert'];
            $metadata .= <<<XML

        <KeyDescriptor use="signing">
            <ds:KeyInfo>
                <ds:X509Data>
                    <ds:X509Certificate>$cert</ds:X509Certificate>
                </ds:X509Data>
            </ds:KeyInfo>
        </KeyDescriptor>
XML;
        }

        $metadata .= <<<XML

    </SPSSODescriptor>
</EntityDescriptor>
XML;

        Log::debug("SAML metadata generated, length: " . strlen($metadata));

        return $metadata;
    }

    /**
     * Get current timestamp in SAML format (ISO 8601)
     */
    protected function getIssueInstant(): string
    {
        return date('Y-m-d\TH:i:s\Z', time());
    }
}
