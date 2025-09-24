# University of Waterloo ADFS SAML Authentication Package

This Laravel package provides SAML authentication integration with University of Waterloo's Active Directory Federation Services (ADFS).

## Features

- SAML 2.0 authentication with UW ADFS
- **Automatic online metadata fetching** with caching and fallback
- Support for both production and development environments
- Automatic user creation and updates
- Group-based access control
- Easy-to-use middleware for route protection
- Configurable attribute mapping
- Single Sign-On (SSO) and Single Logout (SLO)
- Metadata caching for improved performance

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
- `POST /saml/acs` - SAML Assertion Consumer Service
- `GET|POST /saml/sls` - SAML Single Logout Service
- `GET|POST /saml/logout` - Initiate SAML logout
- `GET /saml/metadata` - SP metadata (for ADFS configuration)
- `GET /saml/attributes` - Debug route to view SAML attributes

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

### Metadata Issues

If you encounter metadata-related issues:

1. **Check metadata cache**: Use `php artisan uw-adfs:refresh-metadata --clear-cache`
2. **Verify connectivity**: Ensure your server can reach UW ADFS endpoints
3. **Check logs**: Review Laravel logs for metadata fetch errors
4. **Fallback verification**: Confirm local XML files exist in `storage/app/saml/`

### Common Issues

1. **Certificate Issues**: Ensure the SAML metadata is accessible and contains valid certificates
2. **Clock Skew**: SAML assertions are time-sensitive. Ensure server time is synchronized
3. **URL Mismatch**: Ensure the URLs in your configuration match exactly with what's configured in ADFS
4. **Network Issues**: Check firewall rules if metadata fetching fails
5. **Cache Problems**: Clear metadata cache if you see stale certificate errors

## Key Benefits

1. **Always Up-to-Date**: Metadata is fetched from authoritative UW ADFS sources
2. **High Performance**: Intelligent caching reduces network latency
3. **Reliable**: Robust fallback ensures service continuity during outages
4. **Low Maintenance**: No manual XML file updates required
5. **Comprehensive Monitoring**: Clear logging and administrative commands
6. **Flexible Configuration**: Adaptable behavior for different environments
7. **Backward Compatible**: Existing installations work without changes

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