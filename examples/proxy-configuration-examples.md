# UW ADFS Proxy Configuration Examples

This file demonstrates how to configure the UW ADFS package in proxy mode (Staging AP).

## Scenario 1: Simple Proxy for Staging Environment

```env
# Basic ADFS Configuration
UW_ADFS_ENVIRONMENT=production
UW_ADFS_SP_ENTITY_ID=https://staging-proxy.uwaterloo.ca/proxy
UW_ADFS_SP_ACS_URL=https://staging-proxy.uwaterloo.ca/saml/proxy/acs
UW_ADFS_SP_SLS_URL=https://staging-proxy.uwaterloo.ca/saml/proxy/sls

# Enable Proxy Mode
UW_ADFS_PROXY_ENABLED=true
UW_ADFS_PROXY_MODE=both

# Upstream ADFS Configuration
UW_ADFS_UPSTREAM_IDP_ENTITY_ID=https://adfs.uwaterloo.ca/adfs/services/trust
UW_ADFS_UPSTREAM_SSO_URL=https://adfs.uwaterloo.ca/adfs/ls/
UW_ADFS_UPSTREAM_SLS_URL=https://adfs.uwaterloo.ca/adfs/ls/

# Proxy Settings
UW_ADFS_PROXY_SESSION_LIFETIME=3600
UW_ADFS_PROXY_ATTRIBUTE_FILTERING=true
UW_ADFS_PROXY_SIGN_ASSERTIONS=false

# Access Control (applied at proxy level)
UW_ADFS_DEPARTMENT_RESTRICTION=true
UW_ADFS_ALLOWED_DEPARTMENTS="Mathematics,Computer Science"
UW_ADFS_WHITELIST_ENABLED=true
UW_ADFS_WHITELIST_EMAILS="proxy-admin@uwaterloo.ca"
```

## Scenario 2: Multi-Tenant Proxy with Strict Access Control

```env
# Production Proxy Configuration
UW_ADFS_ENVIRONMENT=production
UW_ADFS_SP_ENTITY_ID=https://auth-proxy.math.uwaterloo.ca/proxy
UW_ADFS_SP_ACS_URL=https://auth-proxy.math.uwaterloo.ca/saml/proxy/acs
UW_ADFS_SP_SLS_URL=https://auth-proxy.math.uwaterloo.ca/saml/proxy/sls

# Proxy Configuration
UW_ADFS_PROXY_ENABLED=true
UW_ADFS_PROXY_MODE=both

# Upstream ADFS
UW_ADFS_UPSTREAM_IDP_ENTITY_ID=https://adfs.uwaterloo.ca/adfs/services/trust
UW_ADFS_UPSTREAM_SSO_URL=https://adfs.uwaterloo.ca/adfs/ls/
UW_ADFS_UPSTREAM_SLS_URL=https://adfs.uwaterloo.ca/adfs/ls/

# Strict Access Control
UW_ADFS_DEPARTMENT_RESTRICTION=true
UW_ADFS_ALLOWED_DEPARTMENTS="Mathematics,Statistics,Applied Mathematics,Combinatorics and Optimization"

UW_ADFS_GROUP_RESTRICTION=true
UW_ADFS_REQUIRED_GROUPS="Faculty,Staff,Graduate Students"
UW_ADFS_BLOCKED_GROUPS="Suspended Accounts,Alumni"

# Admin Access
UW_ADFS_WHITELIST_ENABLED=true
UW_ADFS_WHITELIST_EMAILS="math-admin@uwaterloo.ca,cemc-admin@uwaterloo.ca"

# Enhanced Security
UW_ADFS_PROXY_SIGN_ASSERTIONS=true
UW_ADFS_PROXY_ATTRIBUTE_FILTERING=true
UW_ADFS_PROXY_SESSION_LIFETIME=1800  # 30 minutes
```

## Scenario 3: Development Proxy with Mock Authentication

```env
# Development Environment
UW_ADFS_ENVIRONMENT=development
UW_ADFS_SP_ENTITY_ID=https://localhost:8000/proxy
UW_ADFS_SP_ACS_URL=https://localhost:8000/saml/proxy/acs
UW_ADFS_SP_SLS_URL=https://localhost:8000/saml/proxy/sls

# Enable Proxy for Testing
UW_ADFS_PROXY_ENABLED=true
UW_ADFS_PROXY_MODE=both

# Development Settings (relaxed)
UW_ADFS_PROXY_SESSION_LIFETIME=7200  # 2 hours for development
UW_ADFS_PROXY_ATTRIBUTE_FILTERING=false  # Allow all attributes
UW_ADFS_PROXY_SIGN_ASSERTIONS=false

# Relaxed Access Control for Development
UW_ADFS_DEPARTMENT_RESTRICTION=false
UW_ADFS_GROUP_RESTRICTION=false
UW_ADFS_WHITELIST_ENABLED=false
```

## Client Application Configuration Examples

### Laravel Client App Configuration

```php
// config/saml2.php (or equivalent)
'idp_settings' => [
    'entityId' => 'https://staging-proxy.uwaterloo.ca/proxy',
    'singleSignOnService' => [
        'url' => 'https://staging-proxy.uwaterloo.ca/saml/proxy/sso',
        'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
    ],
    'singleLogoutService' => [
        'url' => 'https://staging-proxy.uwaterloo.ca/saml/proxy/sls',
        'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
    ],
    'x509cert' => '', // Get from proxy metadata if signing is enabled
],

'sp_settings' => [
    'entityId' => 'https://my-app.uwaterloo.ca',
    'assertionConsumerService' => [
        'url' => 'https://my-app.uwaterloo.ca/saml/acs',
        'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
    ],
    'singleLogoutService' => [
        'url' => 'https://my-app.uwaterloo.ca/saml/sls',
        'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
    ],
],
```

### Node.js Client App Configuration

```javascript
// SAML configuration for passport-saml or similar
const samlConfig = {
  // Identity Provider (your proxy)
  entryPoint: 'https://staging-proxy.uwaterloo.ca/saml/proxy/sso',
  logoutUrl: 'https://staging-proxy.uwaterloo.ca/saml/proxy/sls',
  issuer: 'https://my-node-app.uwaterloo.ca',
  
  // Service Provider (your app)
  callbackUrl: 'https://my-node-app.uwaterloo.ca/saml/acs',
  logoutCallbackUrl: 'https://my-node-app.uwaterloo.ca/saml/sls',
  
  // Security settings
  acceptedClockSkewMs: 5000,
  attributeConsumingServiceIndex: false,
  authnContext: false,
  forceAuthn: false,
  identifierFormat: 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
};
```

## Proxy Management Commands

### Check Proxy Status

```bash
# Check if proxy is enabled and configured
curl -s https://your-proxy.uwaterloo.ca/saml/proxy/status | jq

# Expected response:
{
  "proxy_enabled": true,
  "proxy_mode": "both",
  "entity_id": "https://your-proxy.uwaterloo.ca/proxy",
  "endpoints": {
    "sso": "https://your-proxy.uwaterloo.ca/saml/proxy/sso",
    "acs": "https://your-proxy.uwaterloo.ca/saml/proxy/acs",
    "sls": "https://your-proxy.uwaterloo.ca/saml/proxy/sls",
    "metadata": "https://your-proxy.uwaterloo.ca/saml/proxy/metadata"
  }
}
```

### Get Proxy Metadata

```bash
# Download proxy metadata for client configuration
curl https://your-proxy.uwaterloo.ca/saml/proxy/metadata > proxy-metadata.xml

# Validate metadata
xmllint --format proxy-metadata.xml
```

### Monitor Proxy Activity

```bash
# Monitor proxy logs in real-time
tail -f storage/logs/laravel.log | grep "UW ADFS Proxy"

# Search for specific proxy events
grep "UW ADFS Proxy" storage/logs/laravel.log | grep "$(date '+%Y-%m-%d')"
```

## Proxy Architecture Diagram

```
[Client App] --SAML AuthnRequest--> [Your Proxy] --SAML AuthnRequest--> [UW ADFS]
                                         |                                    |
                                    [Access Control]                         |
                                    [Attribute Filter]                       |
                                         |                                    |
[Client App] <--SAML Response------ [Your Proxy] <--SAML Response----- [UW ADFS]
```

## Security Considerations for Proxy Mode

1. **Certificate Management**: Use proper certificates for assertion signing in production
2. **Session Security**: Configure appropriate session lifetime and cleanup
3. **Access Logging**: Monitor all proxy authentication attempts
4. **Client Validation**: Validate and restrict which clients can use your proxy
5. **Attribute Filtering**: Only pass necessary attributes to downstream applications
6. **Network Security**: Ensure proper firewall rules and HTTPS everywhere

## Troubleshooting Proxy Issues

### Common Problems and Solutions

1. **Client Context Lost**
   ```bash
   # Check Laravel session configuration
   php artisan config:show session
   
   # Ensure session driver supports data persistence
   # Redis or database recommended for production
   ```

2. **Upstream ADFS Timeout**
   ```env
   # Increase timeout values
   UW_ADFS_METADATA_TIMEOUT=60
   UW_ADFS_PROXY_SESSION_LIFETIME=1800
   ```

3. **Invalid Client Requests**
   ```bash
   # Enable detailed logging
   LOG_LEVEL=debug
   
   # Check proxy logs for SAML request parsing errors
   grep "Invalid SAML request" storage/logs/laravel.log
   ```

4. **Attribute Filtering Issues**
   ```env
   # Temporarily disable filtering for debugging
   UW_ADFS_PROXY_ATTRIBUTE_FILTERING=false
   
   # Check what attributes are being filtered
   tail -f storage/logs/laravel.log | grep "Attribute filtering"
   ```

This proxy functionality enables sophisticated SAML authentication scenarios while maintaining the security and access control features of the base package.