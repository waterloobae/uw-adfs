# Quick Start: Enable Signed Logout (v1.2.68)

## The Problem You Just Hit
```
MSIS7084: SAML logout request and logout response messages must be signed 
when using SAML HTTP Redirect or HTTP POST binding.
```

**Translation**: ADFS won't accept your logout requests because they're unsigned. This is a security requirement.

## The Fix (3 Steps)

### Step 1: Generate Your SP's Certificate (5 minutes)

```bash
# Run these commands in your terminal
openssl genrsa -out sp-private-key.pem 2048

openssl req -new -x509 -key sp-private-key.pem -out sp-certificate.pem -days 365 \
  -subj "/C=CA/ST=Ontario/L=Waterloo/O=University of Waterloo/CN=sigma-dev.cemc.uwaterloo.ca"

# Show the private key content
cat sp-private-key.pem

# Show the certificate content  
cat sp-certificate.pem
```

### Step 2: Add to .env (2 minutes)

Open `.env` and add (replace the brackets with actual file contents):

```env
UW_ADFS_SP_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----
[PASTE ENTIRE CONTENT OF sp-private-key.pem]
-----END RSA PRIVATE KEY-----"

UW_ADFS_SP_X509_CERT="-----BEGIN CERTIFICATE-----
[PASTE ENTIRE CONTENT OF sp-certificate.pem]
-----END CERTIFICATE-----"
```

### Step 3: Notify UW IT (24-48 hours)

Email UW IT / ADFS team with:
- Your SP Entity ID: `https://sigma-dev.cemc.uwaterloo.ca`
- Your SP certificate (attachment): `sp-certificate.pem`
- Request message: "Please add our Service Provider to ADFS with the attached certificate and enable signed logout request requirement"

## What Changed in v1.2.68

| Feature | Before | After |
|---------|--------|-------|
| Logout Requests | Unsigned ❌ | Signed with RSA-SHA256 ✅ |
| ADFS Acceptance | MSIS7084 Error | Accepted (once cert uploaded to ADFS) |
| Signing | Not implemented | Automatic via openssl |
| Certificate Config | Not required | Required from environment |

## How It Works Now

```
User clicks Logout
    ↓
App signs LogoutRequest XML with SP's private key
    ↓
ADFS verifies signature using SP's public certificate (that you sent them)
    ↓
ADFS processes logout (if signature is valid)
    ↓
User logged out
```

## Testing

1. **Verify private key is loaded**:
   Look for in logs: `SP has private key: yes`

2. **Verify signing works**:
   Look for in logs: `XML signed successfully`

3. **Test logout**:
   ```
   https://sigma-dev.cemc.uwaterloo.ca/saml/logout
   ```

4. **Check logs**:
   ```bash
   tail -f storage/logs/laravel.log | grep -i "logout\|signed"
   ```

## If It Still Doesn't Work

### Certificate not loaded?
```
[Check logs for: SP has private key: no]
↓
Verify .env file exists and contains UW_ADFS_SP_PRIVATE_KEY
↓
Clear config cache: php artisan config:cache
↓
Restart server
```

### ADFS still rejecting?
```
[Check ADFS event log for specific error]
↓
Contact UW IT with event ID and error message
↓
Verify they have:
  1. Added your SP as Relying Party Trust
  2. Imported your certificate
  3. Enabled signed logout requirement
```

## Local-Only Logout (Workaround)

If ADFS setup is delayed, you can logout locally without ADFS involvement:

```
/saml/logout?local_only=1
```

This clears your local session. Users can log back in later.

## Full Documentation

- **Setup Details**: See `SIGNING_CERTIFICATES.md`
- **Technical Details**: See `VERSION_1_2_68_NOTES.md`
- **SAML Spec**: See [OASIS SAML 2.0 XML Signature Syntax](https://docs.oasis-open.org/security/saml/v2.0/saml-core-2.0-os.pdf)

## Key Takeaway

✅ **v1.2.68 automatically signs LogoutRequest messages**  
✅ **You just need to:**
  1. Generate a certificate
  2. Put it in `.env`
  3. Send it to UW IT
  
✅ **Then ADFS logout will work!**
