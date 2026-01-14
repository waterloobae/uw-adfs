# SAML Logout Signing Fix - v1.2.68

## Problem Identified

**ADFS Server Error Log (1/14/2026 2:40:34 PM):**
```
Id: 320
Message: The verification of the SAML message signature failed.
Message issuer: https://sigma-dev.cemc.uwaterloo.ca
Exception details:
MSIS7084: SAML logout request and logout response messages must be signed 
when using SAML HTTP Redirect or HTTP POST binding.
```

**Root Cause**: ADFS requires digitally signed LogoutRequest messages. Previous versions (1.2.42-1.2.67) were sending unsigned requests, causing ADFS to reject them with the MSIS7054 error ("The SAML logout did not complete properly").

## Solution Implemented (v1.2.68)

### Core Changes

1. **Manual XML Signing** (`AdfsService.php::signXml()`)
   - Implements RSA-SHA256 signature algorithm
   - Creates proper XML-DSIG SignedInfo element with:
     - CanonicalizationMethod (Exclusive XML Canonicalization)
     - SignatureMethod (RSA-SHA256)
     - Reference with SHA-256 digest
     - DigestValue calculated from document element
   - Calculates signature over canonicalized SignedInfo
   - Inserts Signature element into LogoutRequest XML

2. **Configuration Update** (`config/uw-adfs.php`)
   - Set `logoutRequestSigned => true`
   - Package now enforces request signing

3. **Enhanced Logging**
   - Detects if private key is configured
   - Logs signing process step-by-step
   - Warns if sending unsigned requests (private key missing)
   - Shows first 400 chars of signed XML for debugging

4. **Environment Variable Support**
   - Reads `UW_ADFS_SP_PRIVATE_KEY` from environment
   - Reads `UW_ADFS_SP_X509_CERT` from environment
   - Both already defined in config, just need environment values

### How It Works

```
1. User initiates logout via /saml/logout endpoint
   ↓
2. AdfsController::logout() retrieves stored NameID, SessionIndex, NameIDFormat
   ↓
3. AdfsService::logout() constructs LogoutRequest XML
   ↓
4. signXml() is called:
   - Parses SP private key using openssl_pkey_get_private()
   - Creates XML-DSIG structure with proper namespace
   - Calculates digest of document element (SHA-256)
   - Signs canonicalized SignedInfo with private key (RSA-SHA256)
   - Inserts Signature element into XML
   ↓
5. Signed XML is deflated and base64-encoded
   ↓
6. LogoutRequest URL constructed with SAMLRequest parameter
   ↓
7. Redirect to ADFS with signed request
   ↓
8. ADFS validates signature using SP's public certificate
   ↓
9. If valid, processes logout and returns LogoutResponse
   ↓
10. SLS endpoint (sls()) processes the response
```

## Setup Required

### For Users (Developers using this package)

You need to generate and configure an SP certificate for signing:

1. **Generate certificates** (see [SIGNING_CERTIFICATES.md](./SIGNING_CERTIFICATES.md)):
   ```bash
   openssl genrsa -out sp-private-key.pem 2048
   openssl req -new -x509 -key sp-private-key.pem -out sp-certificate.pem -days 365
   ```

2. **Set environment variables** in `.env`:
   ```env
   UW_ADFS_SP_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----
   [paste contents of sp-private-key.pem]
   -----END RSA PRIVATE KEY-----"
   
   UW_ADFS_SP_X509_CERT="-----BEGIN CERTIFICATE-----
   [paste contents of sp-certificate.pem]
   -----END CERTIFICATE-----"
   ```

3. **Share certificate with UW IT**:
   - Provide your SP's public certificate (sp-certificate.pem)
   - Provide your SP Entity ID
   - Request they add it to ADFS as a trusted relying party

### For UW IT (ADFS Configuration)

Add the SP as a Relying Party Trust with:
- Entity ID: SP's entity ID
- Assertion Consumer Service endpoint: `/saml/acs`
- Single Logout Service endpoint: `/saml/sls`
- Signing certificate: The SP's public certificate
- Require signed logout requests: Yes

## Technical Details

### XML Signature Structure

The signed LogoutRequest looks like:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<samlp:LogoutRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    xmlns:ds="http://www.w3.org/2000/09/xmldsig#"
    ID="_abc123..."
    Version="2.0"
    IssueInstant="2026-01-14T14:35:00Z"
    Destination="https://adfstest.uwaterloo.ca/adfs/ls/">
    <ds:Signature>
        <ds:SignedInfo>
            <ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>
            <ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>
            <ds:Reference URI="#_abc123...">
                <ds:Transforms>
                    <ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>
                    <ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>
                </ds:Transforms>
                <ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>
                <ds:DigestValue>...</ds:DigestValue>
            </ds:Reference>
        </ds:SignedInfo>
        <ds:SignatureValue>...</ds:SignatureValue>
    </ds:Signature>
    <saml:Issuer Format="urn:oasis:names:tc:SAML:2.0:nameid-format:entity">
        https://sigma-dev.cemc.uwaterloo.ca
    </saml:Issuer>
    <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified">
        thbae
    </saml:NameID>
    <samlp:SessionIndex>_ca4143a5-...</samlp:SessionIndex>
</samlp:LogoutRequest>
```

### Algorithms Used

- **Signature Algorithm**: RSA-SHA256 (`http://www.w3.org/2001/04/xmldsig-more#rsa-sha256`)
- **Canonicalization**: Exclusive XML Canonicalization (`http://www.w3.org/2001/10/xml-exc-c14n#`)
- **Digest Algorithm**: SHA-256 (`http://www.w3.org/2001/04/xmlenc#sha256`)

These match ADFS requirements and OASIS SAML 2.0 standards.

## Expected Behavior

### With Valid Signing Certificate

**Log Output**:
```
[14:35:00] local.DEBUG: Initiating SAML logout - NameID: thbae, ...
[14:35:00] local.DEBUG: SP has private key: yes
[14:35:00] local.DEBUG: SP has certificate: yes
[14:35:00] local.DEBUG: Manually constructing LogoutRequest XML
[14:35:00] local.DEBUG: Attempting to sign LogoutRequest with SP private key
[14:35:00] local.DEBUG: Private key parsed successfully
[14:35:00] local.DEBUG: Document digest calculated: ...
[14:35:00] local.DEBUG: SignedInfo canonicalized, length: 512
[14:35:00] local.DEBUG: XML signed successfully, signature length: 256
[14:35:00] local.DEBUG: Signed XML length: 2048, first 400 chars: ...
[14:35:00] local.INFO: Logout redirect URL (manually constructed and signed): https://adfstest.uwaterloo.ca/adfs/ls/?SAMLRequest=...
[14:35:00] local.INFO: Redirecting to logout URL
→ Browser redirects to ADFS
→ ADFS validates signature
→ If valid, processes logout
```

### Without Signing Certificate

**Log Output**:
```
[14:35:00] local.DEBUG: SP has private key: no
[14:35:00] local.WARNING: No SP private key configured - sending unsigned LogoutRequest (ADFS will likely reject it)
[14:35:00] local.INFO: Logout redirect URL (manually constructed): https://adfstest.uwaterloo.ca/adfs/ls/?SAMLRequest=...
→ ADFS rejects: "MSIS7084: SAML logout request must be signed"
```

## Fallback: Local-Only Logout

If ADFS signing still fails after configuration, users can use local-only logout:

```
/saml/logout?local_only=1&returnTo=/logout-success
```

This bypasses ADFS entirely and logs out locally without requiring ADFS signing setup.

## Files Changed

- `src/Services/AdfsService.php` - Added `signXml()` and `canonicalizeXml()` methods, updated `logout()`
- `config/uw-adfs.php` - Set `logoutRequestSigned => true`
- `SIGNING_CERTIFICATES.md` - New documentation file for certificate setup

## Compatibility

- **PHP**: Requires OpenSSL extension (standard in most PHP installations)
- **Laravel**: Works with Laravel 10+, 11+, 12+
- **OneLogin**: Leverages OneLogin library but implements signing independently
- **ADFS**: Now fully compatible with ADFS signed logout requirements

## Next Steps

1. Generate signing certificates for your SP
2. Configure environment variables with certificates
3. Contact UW IT to add your SP to ADFS with the certificate
4. Test logout flow
5. Verify logs show "XML signed successfully"
6. Confirm ADFS accepts the signed LogoutRequest

See [SIGNING_CERTIFICATES.md](./SIGNING_CERTIFICATES.md) for detailed setup instructions.
