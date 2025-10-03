# University of Waterloo ADFS SAML Authentication Package

This Laravel package provides SAML authentication integration with University of Waterloo's Active Directory Federation Services (ADFS).

## Features

- SAML 2.0 authentication with UW ADFS
- **Automatic online metadata fetching** with caching and fallback
- **Advanced access control** with department, group, and whitelist filtering
- Support for both production and development environments
- Automatic user creation and updates
- Easy-to-use middleware for route protection
- Configurable attribute mapping
- Single Sign-On (SSO) and Single Logout (SLO)
- Metadata caching for improved performance
- Comprehensive logging and access decision tracking
- Custom access denied pages with detailed information

## Requirements

- PHP 8.1 or higher
- Laravel 10.0 or higher
- OneLogin SAML PHP library

## Installation

1. Install the package via Composer:

```bash
composer require waterloobae/uw-adfs
```

2. Publish the configuration file:

```bash
php artisan vendor:publish --tag=uw-adfs-config
```

3. Publish the SAML metadata files:

```bash
php artisan vendor:publish --tag=uw-adfs-metadata
```

4. Configure your environment variables in `.env`:

```env
# ADFS Environment (production or development)
UW_ADFS_ENVIRONMENT=development

# Service Provider Configuration
UW_ADFS_SP_ENTITY_ID=https://your-app.example.com
UW_ADFS_SP_ACS_URL=https://your-app.example.com/saml/acs
UW_ADFS_SP_SLS_URL=https://your-app.example.com/saml/sls

# Optional: SP Certificate and Private Key for signing
UW_ADFS_SP_X509_CERT=
UW_ADFS_SP_PRIVATE_KEY=

# Metadata Configuration (optional)
UW_ADFS_METADATA_CACHE=true
UW_ADFS_METADATA_CACHE_DURATION=3600
UW_ADFS_METADATA_TIMEOUT=30
UW_ADFS_METADATA_FALLBACK_LOCAL=true

# Access Control Configuration (optional)
UW_ADFS_DEPARTMENT_RESTRICTION=false
UW_ADFS_ALLOWED_DEPARTMENTS="Mathematics,Computer Science"
UW_ADFS_WHITELIST_ENABLED=false
UW_ADFS_WHITELIST_EMAILS="admin@uwaterloo.ca,special.user@uwaterloo.ca"
UW_ADFS_GROUP_RESTRICTION=false
UW_ADFS_REQUIRED_GROUPS="Faculty,Staff"
UW_ADFS_BLOCKED_GROUPS="Suspended Accounts,Guest Users"
UW_ADFS_ACCESS_DENIED_MESSAGE="Access denied. Contact administrator."

# User Model (optional, defaults to App\Models\User)
UW_ADFS_USER_MODEL=App\Models\User
```

5. Ensure your User model has the necessary fields:

```php
// database/migrations/xxxx_xx_xx_xxxxxx_create_users_table.php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->string('first_name')->nullable();
    $table->string('last_name')->nullable();
    // ... other fields
    $table->timestamps();
});
```

## Metadata Management

The package automatically fetches SAML metadata from the online UW ADFS endpoints:

- **Production**: `https://adfs.uwaterloo.ca/FederationMetadata/2007-06/FederationMetadata.xml`
- **Development**: `https://adfstest.uwaterloo.ca/FederationMetadata/2007-06/FederationMetadata.xml`

### Metadata Features

- **Automatic caching**: Metadata is cached for 1 hour by default
- **Fallback support**: Falls back to local XML files if online fetch fails
- **Console command**: Refresh metadata manually

### Refresh Metadata Command

```bash
# Refresh metadata for current environment
php artisan uw-adfs:refresh-metadata

# Refresh for specific environment
php artisan uw-adfs:refresh-metadata --environment=production

# Clear cache and refresh
php artisan uw-adfs:refresh-metadata --clear-cache
```

### Local Fallback Files

The local XML files (`dev.xml` and `prod.xml`) serve as:
- **Reference documentation** of ADFS configuration
- **Fallback data** when online endpoints are unavailable
- **Development backup** for offline work
- **Disaster recovery** metadata source

These files are automatically published to `storage/app/saml/` when you install the package.

### Metadata Configuration Options

Configure metadata behavior in your `.env` file:

```env
# Enable/disable metadata caching (default: true)
UW_ADFS_METADATA_CACHE=true

# Cache duration in seconds (default: 3600 = 1 hour)
UW_ADFS_METADATA_CACHE_DURATION=3600

# HTTP timeout for fetching metadata (default: 30 seconds)
UW_ADFS_METADATA_TIMEOUT=30

# Enable fallback to local files (default: true)
UW_ADFS_METADATA_FALLBACK_LOCAL=true
```

## Configuration

### Environment Configuration

The package supports both production and development ADFS environments. Set `UW_ADFS_ENVIRONMENT` in your `.env` file:

- `production`: Uses `adfs.uwaterloo.ca`
- `development`: Uses `adfstest.uwaterloo.ca`

### Attribute Mapping

Configure how SAML attributes map to your user model in `config/uw-adfs.php`:

```php
'attribute_mapping' => [
    'name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
    'email' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
    'first_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
    'last_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
    'groups' => 'http://schemas.xmlsoap.org/claims/Group',
],
```

## Usage

### Basic Authentication

1. **Login Link**: Direct users to the SAML login:

```php
<a href="{{ route('saml.login') }}">Login with UW ADFS</a>
```

2. **Logout Link**: Logout from both your app and ADFS:

```php
<a href="{{ route('saml.logout') }}">Logout</a>
```

### Route Protection

Use the provided middleware to protect routes:

```php
// Basic ADFS authentication
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['adfs.auth']);

// Group-based access control
Route::get('/admin', function () {
    return view('admin');
})->middleware(['adfs.group:Domain Admins']);
```

Register the middleware in your `app/Http/Kernel.php`:

```php
protected $routeMiddleware = [
    // ... other middleware
    'adfs.auth' => \WaterlooBae\UwAdfs\Http\Middleware\AdfsAuthenticated::class,
    'adfs.group' => \WaterlooBae\UwAdfs\Http\Middleware\AdfsGroup::class,
];
```

### Using the Service

You can also use the ADFS service directly:

```php
use WaterlooBae\UwAdfs\Facades\UwAdfs;

// Get user groups from current session
$samlSession = session('saml_session');
$groups = UwAdfs::getUserGroups($samlSession['attributes']);

// Check if user has specific group
$hasAccess = UwAdfs::userHasGroup($samlSession['attributes'], 'Required Group Name');
```

### Available Routes

The package registers the following routes:

- `GET /saml/login` - Initiate SAML login
- `POST /saml/acs` - SAML Assertion Consumer Service (includes access control)
- `GET|POST /saml/sls` - SAML Single Logout Service
- `GET|POST /saml/logout` - Initiate SAML logout
- `GET /saml/metadata` - SP metadata (for ADFS configuration)
- `GET /saml/attributes` - Debug route to view SAML attributes
- `GET /access-denied` - Access denied page with detailed information

## ADFS Configuration

Provide your ADFS administrator with:

1. **SP Metadata URL**: `https://your-app.example.com/saml/metadata`
2. **Assertion Consumer Service URL**: `https://your-app.example.com/saml/acs`
3. **Single Logout Service URL**: `https://your-app.example.com/saml/sls`

## Group-Based Access Control

To restrict access based on Active Directory groups, use the `adfs.group` middleware:

```php
Route::group(['middleware' => ['adfs.group:Faculty']], function () {
    Route::get('/faculty-only', 'FacultyController@index');
});
```

## Troubleshooting

### Debug SAML Attributes

Visit `/saml/attributes` (when logged in) to see available SAML attributes and session data.

### Access Control Issues

1. **Access Denied Unexpectedly**: Check Laravel logs for access control decisions
2. **Department Not Recognized**: Verify department attribute is being sent by ADFS
3. **Groups Not Working**: Confirm group attribute mapping in configuration
4. **Whitelist Not Working**: Ensure email addresses match exactly (case-insensitive)

### Development Issues

1. **Mock Login Not Working**: Ensure you're in `local` or `development` environment
2. **ngrok Issues**: Check if tunnel is active and URL is updated in config
3. **ADFS Whitelist**: Contact ADFS admin to allow your ngrok subdomain

### Metadata Issues

If you encounter metadata-related issues:

1. **Check metadata cache**: Use `php artisan uw-adfs:refresh-metadata --clear-cache`
2. **Verify connectivity**: Ensure your server can reach UW ADFS endpoints
3. **Check logs**: Review Laravel logs for metadata fetch errors
4. **Fallback verification**: Confirm local XML files exist in `storage/app/saml/`

### Common Issues

1. **Certificate Issues**: Ensure the SAML metadata is accessible and contains valid certificates\n2. **Clock Skew**: SAML assertions are time-sensitive. Ensure server time is synchronized\n3. **URL Mismatch**: Ensure the URLs in your configuration match exactly with what's configured in ADFS\n4. **Network Issues**: Check firewall rules if metadata fetching fails\n5. **Cache Problems**: Clear metadata cache if you see stale certificate errors\n6. **Proxy Issues**: When using proxy mode, check proxy status and logs\n\n   ```bash\n   # Check proxy status\n   curl https://your-app.uwaterloo.ca/saml/proxy/status\n   \n   # Check proxy logs\n   tail -f storage/logs/laravel.log | grep \"UW ADFS Proxy\"\n   ```\n\n   Common proxy issues:\n   - **Missing Client Context**: Session data lost between request and response\n   - **Upstream Timeout**: UW ADFS taking too long to respond\n   - **Attribute Filtering**: Required attributes being filtered out\n   - **Invalid Client Requests**: Malformed SAML requests from client apps">

## Access Control & User Filtering

The package includes comprehensive access control features to restrict access based on departments, groups, and whitelists.

### Department-Based Access Control

Restrict access to users from specific departments:

```env
# Enable department filtering
UW_ADFS_DEPARTMENT_RESTRICTION=true

# Allow only Math and Computer Science departments
UW_ADFS_ALLOWED_DEPARTMENTS="Mathematics,Computer Science,Statistics"
```

### Email Whitelist

Allow specific users regardless of other restrictions:

```env
# Enable whitelist
UW_ADFS_WHITELIST_ENABLED=true

# Specific users who should always have access
UW_ADFS_WHITELIST_EMAILS="admin@uwaterloo.ca,director@math.uwaterloo.ca,special.user@uwaterloo.ca"
```

### Group-Based Access Control

Control access based on Active Directory group membership:

```env
# Enable group restrictions
UW_ADFS_GROUP_RESTRICTION=true

# Users must belong to at least one of these groups
UW_ADFS_REQUIRED_GROUPS="Faculty,Staff,Graduate Students"

# Block users from these groups
UW_ADFS_BLOCKED_GROUPS="Suspended Accounts,Inactive Users"
```

### Access Control Hierarchy

1. **Whitelist** (if enabled): Overrides all other restrictions
2. **Blocked Groups**: Users in these groups are denied access
3. **Required Groups**: Users must belong to at least one
4. **Department Restrictions**: Users must be from allowed departments

### Custom Access Denied Page

```env
# Custom access denied configuration
UW_ADFS_ACCESS_DENIED_URL="/custom-access-denied"
UW_ADFS_ACCESS_DENIED_MESSAGE="Access restricted to authorized personnel only."
```

### Access Control Examples

See `examples/access-control-examples.env` for complete configuration scenarios.

## Complete Implementation Example

### Environment Configuration (.env)

```env
# Basic ADFS Configuration
UW_ADFS_ENVIRONMENT=production
UW_ADFS_SP_ENTITY_ID=https://cemc.uwaterloo.ca
UW_ADFS_SP_ACS_URL=https://cemc.uwaterloo.ca/saml/acs
UW_ADFS_SP_SLS_URL=https://cemc.uwaterloo.ca/saml/sls

# Access Control - Mathematics Department Only
UW_ADFS_DEPARTMENT_RESTRICTION=true
UW_ADFS_ALLOWED_DEPARTMENTS="Mathematics,Statistics"

# Whitelist for Special Users
UW_ADFS_WHITELIST_ENABLED=true
UW_ADFS_WHITELIST_EMAILS="admin@uwaterloo.ca,cemc-director@math.uwaterloo.ca"

# Group Restrictions
UW_ADFS_GROUP_RESTRICTION=true
UW_ADFS_REQUIRED_GROUPS="Faculty,Staff,Graduate Students"
UW_ADFS_BLOCKED_GROUPS="Suspended Accounts"

# Custom Messages
UW_ADFS_ACCESS_DENIED_MESSAGE="Access restricted to Mathematics department members."
```

### Route Configuration (routes/web.php)

```php
// Public routes
Route::get('/', function () {
    return view('welcome');
});

// Protected routes with ADFS authentication + access control
Route::middleware(['adfs.auth'])->group(function () {
    Route::get('/dashboard', 'DashboardController@index');
    Route::get('/member-resources', 'ResourceController@index');
});

// Faculty-only routes
Route::middleware(['adfs.group:Faculty'])->group(function () {
    Route::get('/faculty-tools', 'FacultyController@tools');
});

// Admin routes with multiple restrictions
Route::middleware(['adfs.group:Administrators'])->group(function () {
    Route::get('/admin', 'AdminController@index');
});
```

### Controller Implementation

```php
<?php

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $attributes = session('saml_attributes', []);
        
        return view('dashboard', [
            'user' => $user,
            'department' => $attributes['department'][0] ?? 'Unknown',
            'groups' => $attributes['memberOf'] ?? []
        ]);
    }
}
```

## SAML Proxy/Staging AP Support\n\nThe package now supports **SAML Proxy mode** (also known as Staging Authentication Proxy), allowing your application to act as an intermediary between client applications and UW ADFS. This is particularly useful for:\n\n- **Staging Environments**: Provide ADFS authentication for development/staging without direct ADFS integration\n- **Multi-Tier Architectures**: Centralize authentication through a proxy layer\n- **Attribute Filtering**: Control which SAML attributes are passed to downstream applications\n- **Access Control Layering**: Apply additional access control before forwarding authentication\n\n### Proxy Configuration\n\n```env\n# Enable proxy mode\nUW_ADFS_PROXY_ENABLED=true\n\n# Proxy mode: 'server' (for clients), 'client' (to ADFS), 'both'\nUW_ADFS_PROXY_MODE=both\n\n# Upstream ADFS configuration (when acting as client)\nUW_ADFS_UPSTREAM_IDP_ENTITY_ID=https://adfs.uwaterloo.ca/adfs/services/trust\nUW_ADFS_UPSTREAM_SSO_URL=https://adfs.uwaterloo.ca/adfs/ls/\nUW_ADFS_UPSTREAM_SLS_URL=https://adfs.uwaterloo.ca/adfs/ls/\n\n# Proxy settings\nUW_ADFS_PROXY_SESSION_LIFETIME=3600\nUW_ADFS_PROXY_ATTRIBUTE_FILTERING=true\nUW_ADFS_PROXY_SIGN_ASSERTIONS=true\n```\n\n### Proxy Endpoints\n\nWhen proxy mode is enabled, these additional endpoints are available:\n\n- **SSO Endpoint**: `/saml/proxy/sso` - Receives authentication requests from client apps\n- **ACS Endpoint**: `/saml/proxy/acs` - Processes responses from upstream ADFS\n- **SLS Endpoint**: `/saml/proxy/sls` - Handles logout requests\n- **Metadata**: `/saml/proxy/metadata` - Proxy metadata for client applications\n- **Status**: `/saml/proxy/status` - Proxy configuration and health status\n\n### Client Application Configuration\n\nClient applications should configure their SAML settings to use your proxy:\n\n```php\n// Client app SAML configuration\n'idp' => [\n    'entityId' => 'https://your-proxy.uwaterloo.ca/proxy',\n    'singleSignOnService' => [\n        'url' => 'https://your-proxy.uwaterloo.ca/saml/proxy/sso',\n        'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',\n    ],\n    'singleLogoutService' => [\n        'url' => 'https://your-proxy.uwaterloo.ca/saml/proxy/sls',\n        'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',\n    ],\n],\n```\n\n### Proxy Flow\n\n1. **Client Request**: Client app sends SAML auth request to proxy SSO endpoint\n2. **Request Processing**: Proxy validates and stores client context\n3. **Upstream Forward**: Proxy forwards authentication to UW ADFS\n4. **ADFS Response**: UW ADFS authenticates user and responds to proxy\n5. **Access Control**: Proxy applies access control rules\n6. **Attribute Filtering**: Proxy filters attributes based on configuration\n7. **Client Response**: Proxy generates and sends SAML response to client\n\n## Key Benefits\n\n1. **Always Up-to-Date**: Metadata is fetched from authoritative UW ADFS sources\n2. **High Performance**: Intelligent caching reduces network latency\n3. **Reliable**: Robust fallback ensures service continuity during outages\n4. **Low Maintenance**: No manual XML file updates required\n5. **Comprehensive Monitoring**: Clear logging and administrative commands\n6. **Flexible Configuration**: Adaptable behavior for different environments\n7. **Advanced Access Control**: Department, group, and whitelist filtering\n8. **SAML Proxy Support**: Act as intermediary for staging and multi-tier scenarios\n9. **Backward Compatible**: Existing installations work without changes">

## Security Considerations

1. Always use HTTPS in production
2. Keep your SP private key secure
3. Regularly update the IdP metadata files
4. Consider enabling assertion signing in production
5. Remove or protect the debug `/saml/attributes` route in production

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Support

For issues related to this package, please open an issue on the GitHub repository.

For ADFS configuration issues, contact the University of Waterloo IT Services.