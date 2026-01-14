# SAML Logout Request Signing - Certificate Setup

## Overview

As of version 1.2.68, the UW ADFS package now **signs all LogoutRequest messages**. This is a requirement from ADFS when using HTTP-Redirect binding:

```
MSIS7084: SAML logout request and logout response messages must be signed 
when using SAML HTTP Redirect or HTTP POST binding.
```

## Why Signing is Required

ADFS validates that logout requests are digitally signed to ensure they come from an authenticated Service Provider. The signature proves:
1. The message hasn't been tampered with
2. The request originated from your configured Service Provider
3. Your SP possesses the corresponding private key

## Setup Instructions

### 1. Generate Self-Signed Certificate for Your SP (if you don't have one)

If you don't have SP certificates configured, generate them using OpenSSL:

```bash
# Generate private key (2048-bit RSA)
openssl genrsa -out sp-private-key.pem 2048

# Generate self-signed certificate (valid for 365 days)
openssl req -new -x509 -key sp-private-key.pem -out sp-certificate.pem -days 365 \
  -subj "/C=CA/ST=Ontario/L=Waterloo/O=University of Waterloo/CN=sigma-dev.cemc.uwaterloo.ca"

# Extract certificate in DER format for ADFS (if needed)
openssl x509 -in sp-certificate.pem -outform DER -out sp-certificate.der
```

### 2. Configure Environment Variables

Set these environment variables in your `.env` file:

```env
# Private key - paste the ENTIRE contents of sp-private-key.pem
UW_ADFS_SP_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEA2x...
[paste entire private key content here]
...
-----END RSA PRIVATE KEY-----"

# Certificate - paste the ENTIRE contents of sp-certificate.pem  
UW_ADFS_SP_X509_CERT="-----BEGIN CERTIFICATE-----
MIIDXTCCAkWgAwIBAgI...
[paste entire certificate content here]
...
-----END CERTIFICATE-----"

# Enable signing for logout requests
UW_ADFS_ENVIRONMENT=development
UW_ADFS_SP_ENTITY_ID=https://sigma-dev.cemc.uwaterloo.ca
```

### 3. Import Certificate to ADFS

**This step requires coordination with UW IT / ADFS administrators.**

You need to provide them with:
1. Your SP's certificate (sp-certificate.pem or sp-certificate.der)
2. Your SP's Entity ID (e.g., https://sigma-dev.cemc.uwaterloo.ca)
3. Request that they add your certificate to ADFS's list of trusted SPs

ADFS administrators will need to:
1. Add your SP as a relying party trust
2. Import your certificate as the signing certificate
3. Enable signed logout request requirement
4. Configure the SAML binding endpoints

### 4. Verify Configuration

The package will log when certificates are present:

```
SP has private key: yes
SP has certificate: yes
Attempting to sign LogoutRequest with SP private key
LogoutRequest signed successfully
```

If you see "has private key: no", check your `.env` file configuration.

## Certificate Format

The private key and certificate must be in **PEM format** (text format with BEGIN/END markers):

```
-----BEGIN RSA PRIVATE KEY-----
[base64 encoded data]
-----END RSA PRIVATE KEY-----
```

```
-----BEGIN CERTIFICATE-----
[base64 encoded data]
-----END CERTIFICATE-----
```

### Invalid Formats (don't use):
- ❌ DER format (binary) - convert with: `openssl x509 -inform DER -in cert.der -out cert.pem`
- ❌ PKCS#12 (.pfx/.p12) - extract with: `openssl pkcs12 -in cert.pfx -out cert.pem -nodes`

## Troubleshooting

### Error: "Failed to parse private key"

**Cause**: Private key is not valid PEM format
**Solution**: 
1. Verify the key includes both BEGIN/END markers
2. Ensure no extra spaces/newlines outside the markers
3. Check the key is RSA format, not EC or DSA

```bash
# Verify key format
openssl pkey -in sp-private-key.pem -text -noout
```

### Error: "Failed to sign XML"

**Cause**: OpenSSL extension not available or signature operation failed
**Solution**:
1. Verify OpenSSL PHP extension is enabled: `php -m | grep openssl`
2. Check PHP error logs for specific OpenSSL errors
3. Verify private key matches certificate (optional step):
```bash
openssl rsa -in sp-private-key.pem -pubout -out pubkey.pem
openssl x509 -in sp-certificate.pem -pubkey -noout > pubkey_cert.pem
diff pubkey.pem pubkey_cert.pem
```

### ADFS Error: "MSIS7054: The SAML logout did not complete properly"

**After implementing signing**, if you still see MSIS7054:

1. **Verify certificate is imported to ADFS** - Contact UW IT to confirm
2. **Check ADFS event logs** - Look for specific certificate validation errors
3. **Verify SessionIndex matches** - The SessionIndex must match what ADFS has on record
4. **Check certificate expiration** - If cert is expired, generate a new one and update ADFS

### Logs showing unsigned request

**Message**: "No SP private key configured - sending unsigned LogoutRequest"

**Solution**: Your `.env` variables are not set. Check:
1. Variables are in `.env` (not `.env.example`)
2. Keys are set correctly (copy/paste from generated files)
3. Laravel cache is cleared: `php artisan config:cache`

## Manual Certificate Rotation

To rotate certificates (e.g., before expiration):

1. Generate new key and certificate using steps above
2. Update `.env` with new values
3. Notify UW IT / ADFS administrators
4. They update the certificate in ADFS
5. Clear application cache: `php artisan config:cache`

## Development vs Production

### Development (adfstest)
- Self-signed certificates are fine
- Certificate name doesn't need to match exactly
- Can regenerate frequently for testing

### Production (adfs.uwaterloo.ca)
- Should use organization-signed certificates or valid FQDN-matched certs
- Certificate must be added to ADFS production environment
- Follow your organization's certificate lifecycle procedures
- Test thoroughly in development first

## Security Considerations

1. **Private Key Protection**:
   - Never commit `.env` or private keys to Git
   - Store `.env` securely with appropriate permissions (600)
   - Use secrets management for production (AWS Secrets, HashiCorp Vault, etc.)

2. **Certificate Expiration**:
   - Monitor certificate expiration dates
   - Set calendar reminders to renew before expiration
   - Expired certificates will cause logout to fail

3. **Key Compromise**:
   - If private key is exposed, regenerate immediately
   - Update ADFS with new certificate
   - Revoke old certificate if possible

## Further Reading

- [SAML 2.0 XML Signature Syntax and Processing](http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0.pdf)
- [Microsoft ADFS Technical Reference](https://learn.microsoft.com/en-us/windows-server/identity/ad-fs/overview/)
- [OneLogin SAML Toolkit Documentation](https://github.com/onelogin/php-saml)
