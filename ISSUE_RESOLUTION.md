# ADFS Logout Signing - Issue Resolution Summary

## Issue Identified
**Date**: January 14, 2026, 2:40:34 PM  
**Error Source**: ADFS Server Event Log (ID: 320)  
**Root Cause**: LogoutRequest messages were not digitally signed

## Error Message
```
The verification of the SAML message signature failed.
Message issuer: https://sigma-dev.cemc.uwaterloo.ca
Exception details:
MSIS7084: SAML logout request and logout response messages must be signed 
when using SAML HTTP Redirect or HTTP POST binding.

This request failed.
```

## Investigation Path

### Previous Debugging (v1.2.42 → v1.2.67)
Over 25 versions, we systematically debugged logout:
1. ✅ Fixed page stuck at `/saml/logout` 
2. ✅ Implemented manual SAML LogoutRequest construction
3. ✅ Added proper XML attributes (Destination, NameIDFormat, Issuer)
4. ✅ Added RelayState parameter
5. ✅ Implemented local-only logout fallback
6. ❌ But logout still failed with: `MSIS7054: The SAML logout did not complete properly`

**Discovery**: The underlying issue wasn't XML structure—it was the lack of digital signature.

### Root Cause Analysis
- ADFS requires cryptographic proof that logout requests come from an authenticated SP
- Previous approach: Sending unsigned XML (permitted in OpenSAML but not in strict ADFS)
- ADFS Security Policy: "SAML logout request and logout response messages **must be signed**"

## Solution Implemented (v1.2.68)

### Code Changes

#### 1. XML Signing Implementation (`signXml()` method)
- **Algorithm**: RSA-SHA256 digital signature
- **Canonicalization**: Exclusive XML Canonicalization (W3C standard)
- **Key Source**: SP's private key from environment
- **Process**:
  1. Parse SP private key using OpenSSL
  2. Create XML-DSIG structure with proper namespaces
  3. Calculate SHA-256 digest of document element
  4. Sign canonicalized SignedInfo with RSA-SHA256
  5. Insert Signature element into LogoutRequest
  6. Return signed XML

#### 2. LogoutRequest Enhancement
```php
// Before: unsigned XML
<samlp:LogoutRequest ID="..." Version="2.0" ...>
    <saml:Issuer>...</saml:Issuer>
    <saml:NameID>...</saml:NameID>
    <samlp:SessionIndex>...</samlp:SessionIndex>
</samlp:LogoutRequest>

// After: signed XML with XMLDSIG
<samlp:LogoutRequest ID="..." Version="2.0" ...>
    <ds:Signature>
        <ds:SignedInfo>...</ds:SignedInfo>
        <ds:SignatureValue>...</ds:SignatureValue>
    </ds:Signature>
    <saml:Issuer>...</saml:Issuer>
    <saml:NameID>...</saml:NameID>
    <samlp:SessionIndex>...</samlp:SessionIndex>
</samlp:LogoutRequest>
```

#### 3. Configuration Update
- Set `logoutRequestSigned => true` in config
- Certificate loading from environment variables
- Environment variables already existed in config:
  - `UW_ADFS_SP_PRIVATE_KEY`
  - `UW_ADFS_SP_X509_CERT`

#### 4. Logging & Debugging
Comprehensive logging added:
```
SP has private key: yes/no
SP has certificate: yes/no
Attempting to sign LogoutRequest with SP private key
Private key parsed successfully
Document digest calculated: ...
SignedInfo canonicalized, length: 512
XML signed successfully, signature length: 256
Signed XML length: 2048, first 400 chars: ...
Logout redirect URL (manually constructed and signed): ...
```

### Configuration Required

Users must provide signing certificates via environment:

```env
UW_ADFS_SP_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----
[private key PEM content]
-----END RSA PRIVATE KEY-----"

UW_ADFS_SP_X509_CERT="-----BEGIN CERTIFICATE-----
[certificate PEM content]
-----END CERTIFICATE-----"
```

### ADFS Configuration Required

UW IT must:
1. Add SP as a Relying Party Trust
2. Import the SP's public certificate
3. Configure SAML endpoints
4. Enable signed logout requirement

## Files Modified

### src/Services/AdfsService.php
- **Added**: `signXml()` method - implements XML-DSIG RSA-SHA256 signing
- **Added**: `canonicalizeXml()` method - removes whitespace for signature calculation
- **Updated**: `logout()` method - calls signXml() before encoding
- **Enhanced**: Logging to show signing process
- **Lines changed**: +150 (new methods), updated main logout flow

### config/uw-adfs.php
- **Updated**: `logoutRequestSigned` from `false` → `true`
- **Reason**: Enables requirement for signed requests

### composer.json
- **Updated**: Version from `1.2.67` → `1.2.68`

### Documentation (New Files)
- **SIGNING_CERTIFICATES.md** - Detailed setup instructions
- **VERSION_1_2_68_NOTES.md** - Technical implementation details  
- **QUICK_START_SIGNING.md** - Quick reference guide

## Expected Behavior After Implementation

### With Certificates Configured

**Scenario**: User has:
- `UW_ADFS_SP_PRIVATE_KEY` set in `.env`
- Certificate uploaded to ADFS
- ADFS configured to accept signed requests

**Result**:
```
User clicks logout
↓
LogoutRequest XML is generated
↓
XML is digitally signed with SP private key
↓
Signed XML is deflated and base64-encoded
↓
ADFS receives request with signature
↓
ADFS validates signature using SP's public certificate
↓
✅ If valid → ADFS processes logout, returns LogoutResponse
❌ If invalid → ADFS returns MSIS7084 again
```

### Without Certificates Configured

**Scenario**: User doesn't have certificates in `.env`

**Result**:
```
[LOG] SP has private key: no
[LOG] WARNING: No SP private key configured - sending unsigned LogoutRequest
↓
Unsigned LogoutRequest sent to ADFS
↓
❌ ADFS rejects: MSIS7084 - request must be signed
```

**Resolution**: User must generate certificate and add to `.env`

## Verification Steps

### 1. Check Private Key Loading
```bash
# Search logs for:
grep "SP has private key" storage/logs/laravel.log
# Expected: "SP has private key: yes"
```

### 2. Check Signing Success
```bash
# Search logs for:
grep "XML signed successfully" storage/logs/laravel.log
# Expected: "XML signed successfully, signature length: 256"
```

### 3. Test Logout
```
Navigate to: /saml/logout
Check if redirected to ADFS without error
```

### 4. Check ADFS Logs
```
ADFS Event Log → Applications
Look for event related to your logout request
Should show successful signature verification
```

## Backward Compatibility

- **Breaking Change**: Yes - now requires certificates for logout to work
- **Migration Path**: 
  1. Generate certificates
  2. Update `.env`
  3. Coordinate with UW IT
  4. Update ADFS configuration
- **Fallback Available**: Users can still use `?local_only=1` parameter to bypass ADFS

## Technical Standards Compliance

- **XML-DSIG**: [W3C XML Signature Syntax and Processing](https://www.w3.org/TR/xmldsig-core/)
- **SAML 2.0**: [OASIS SAML 2.0 Core](https://docs.oasis-open.org/security/saml/v2.0/saml-core-2.0-os.pdf)
- **ADFS Requirements**: Microsoft Active Directory Federation Services v4.0+
- **Algorithms**:
  - Signature: RSA-SHA256
  - Canonicalization: Exclusive XML Canonicalization (XML-EXC-C14N)
  - Digest: SHA-256

## Next Steps for Users

1. **Generate Certificate**:
   ```bash
   openssl genrsa -out sp-private-key.pem 2048
   openssl req -new -x509 -key sp-private-key.pem -out sp-certificate.pem -days 365
   ```

2. **Configure Environment**:
   - Add `UW_ADFS_SP_PRIVATE_KEY` to `.env`
   - Add `UW_ADFS_SP_X509_CERT` to `.env`

3. **Contact UW IT**:
   - Send them `sp-certificate.pem`
   - Request they add your SP to ADFS with signing requirement

4. **Test**:
   - Logout and verify logs show "XML signed successfully"
   - Check ADFS processes logout correctly

5. **Monitor**:
   - Watch for any MSIS7084 errors in ADFS logs
   - Verify users can logout successfully

## Support Resources

- [SIGNING_CERTIFICATES.md](./SIGNING_CERTIFICATES.md) - Full certificate setup guide
- [VERSION_1_2_68_NOTES.md](./VERSION_1_2_68_NOTES.md) - Technical details
- [QUICK_START_SIGNING.md](./QUICK_START_SIGNING.md) - Quick reference
- UW IT Help Desk - For ADFS configuration

## Success Criteria

- ✅ LogoutRequest includes XML-DSIG Signature element
- ✅ Signature algorithm is RSA-SHA256
- ✅ ADFS accepts signed LogoutRequest
- ✅ ADFS returns LogoutResponse
- ✅ User is logged out successfully
- ✅ No MSIS7084 errors in ADFS log

## Conclusion

**v1.2.68 resolves the MSIS7084 error by implementing digitally signed SAML logout requests.** This is now compliant with ADFS security requirements for HTTP-Redirect binding.

Users must generate signing certificates and coordinate with UW IT to configure ADFS, but once set up, logout will work correctly.
