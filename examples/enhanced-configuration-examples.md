# Enhanced UW ADFS Configuration Examples

This file demonstrates the enhanced attribute mapping and configuration options available in the UW ADFS package.

## Complete Attribute Mapping Configuration

Your package now supports these attributes in `config/uw-adfs.php`:

```php
'attribute_mapping' => [
    // Core identity attributes
    'name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
    'email' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
    'first_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
    'last_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
    
    // Organizational attributes
    'department' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/department',
    'title' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/title',
    'employee_id' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/employeeid',
    'employee_type' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/employeetype',
    
    // Contact information
    'phone' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/phone',
    'office' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/streetaddress',
    
    // Group membership (multiple possible claim names for compatibility)
    'groups' => [
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/memberof',
        'http://schemas.xmlsoap.org/claims/Group',
        'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups',
    ],
    
    // Additional UW-specific attributes
    'student_id' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/studentid',
    'faculty' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/faculty',
    'program' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/program',
],
```

## Environment Configuration Examples

### Example 1: Mathematics Department with Enhanced Attributes

```env
# Basic ADFS Configuration
UW_ADFS_ENVIRONMENT=production
UW_ADFS_SP_ENTITY_ID=https://math-portal.uwaterloo.ca
UW_ADFS_SP_ACS_URL=https://math-portal.uwaterloo.ca/saml/acs
UW_ADFS_SP_SLS_URL=https://math-portal.uwaterloo.ca/saml/sls

# Department-based Access Control
UW_ADFS_DEPARTMENT_RESTRICTION=true
UW_ADFS_ALLOWED_DEPARTMENTS="Mathematics,Statistics,Applied Mathematics"

# Group-based Access Control
UW_ADFS_GROUP_RESTRICTION=true
UW_ADFS_REQUIRED_GROUPS="Faculty,Staff,Graduate Students,Postdocs"
UW_ADFS_BLOCKED_GROUPS="Suspended Accounts,Inactive Users"

# Admin Whitelist
UW_ADFS_WHITELIST_ENABLED=true
UW_ADFS_WHITELIST_EMAILS="math-admin@uwaterloo.ca,dept-chair@math.uwaterloo.ca"
```

### Example 2: CEMC Portal with Multiple Faculty Access

```env
# CEMC Portal Configuration
UW_ADFS_ENVIRONMENT=production
UW_ADFS_SP_ENTITY_ID=https://cemc.uwaterloo.ca
UW_ADFS_SP_ACS_URL=https://cemc.uwaterloo.ca/saml/acs
UW_ADFS_SP_SLS_URL=https://cemc.uwaterloo.ca/saml/sls

# Multi-Department Access
UW_ADFS_DEPARTMENT_RESTRICTION=true
UW_ADFS_ALLOWED_DEPARTMENTS="Mathematics,Computer Science,Statistics,Applied Mathematics,Combinatorics and Optimization"

# Specific Group Requirements
UW_ADFS_GROUP_RESTRICTION=true
UW_ADFS_REQUIRED_GROUPS="CEMC Members,Faculty,Staff"

# Special Access for Key Personnel
UW_ADFS_WHITELIST_ENABLED=true
UW_ADFS_WHITELIST_EMAILS="cemc-director@math.uwaterloo.ca,cemc-admin@uwaterloo.ca,outreach@uwaterloo.ca"

# Custom Access Denied Page
UW_ADFS_ACCESS_DENIED_MESSAGE="Access to CEMC resources is restricted to authorized faculty and staff."
```

### Example 3: Development Environment with Mock Authentication

```env
# Development Configuration
UW_ADFS_ENVIRONMENT=development
UW_ADFS_SP_ENTITY_ID=https://localhost
UW_ADFS_SP_ACS_URL=https://localhost/saml/acs
UW_ADFS_SP_SLS_URL=https://localhost/saml/sls

# Relaxed Access Control for Testing
UW_ADFS_DEPARTMENT_RESTRICTION=false
UW_ADFS_GROUP_RESTRICTION=false
UW_ADFS_WHITELIST_ENABLED=false

# Mock user data for local development
UW_ADFS_MOCK_USER_EMAIL=testuser@uwaterloo.ca
UW_ADFS_MOCK_USER_NAME="Test User"
UW_ADFS_MOCK_USER_DEPARTMENT=Mathematics
UW_ADFS_MOCK_USER_GROUPS="Faculty,CEMC Members"
```

### Example 4: Student Portal with Program-Based Access

```env
# Student Portal Configuration
UW_ADFS_ENVIRONMENT=production
UW_ADFS_SP_ENTITY_ID=https://student-portal.math.uwaterloo.ca
UW_ADFS_SP_ACS_URL=https://student-portal.math.uwaterloo.ca/saml/acs
UW_ADFS_SP_SLS_URL=https://student-portal.math.uwaterloo.ca/saml/sls

# Program-based Department Access
UW_ADFS_DEPARTMENT_RESTRICTION=true
UW_ADFS_ALLOWED_DEPARTMENTS="Mathematics,Computer Science,Statistics,Applied Mathematics,Combinatorics and Optimization"

# Student and Faculty Access
UW_ADFS_GROUP_RESTRICTION=true
UW_ADFS_REQUIRED_GROUPS="Students,Graduate Students,Faculty,Staff"
UW_ADFS_BLOCKED_GROUPS="Alumni,Suspended Accounts"

# No whitelist needed for student portal
UW_ADFS_WHITELIST_ENABLED=false
```

## Advanced Usage Examples

### Accessing Enhanced User Attributes in Controllers

```php
<?php

class UserProfileController extends Controller
{
    public function show()
    {
        $user = Auth::user();
        $samlAttributes = session('saml_session.attributes', []);
        
        // Map all available attributes
        $userData = app(AdfsService::class)->mapAttributes($samlAttributes);
        
        return view('profile.show', [
            'user' => $user,
            'profile' => [
                'name' => $userData['name'] ?? 'Unknown',
                'email' => $userData['email'] ?? '',
                'department' => $userData['department'] ?? 'Unknown',
                'title' => $userData['title'] ?? 'Unknown',
                'phone' => $userData['phone'] ?? 'Not provided',
                'office' => $userData['office'] ?? 'Not provided',
                'employee_id' => $userData['employee_id'] ?? 'Unknown',
                'employee_type' => $userData['employee_type'] ?? 'Unknown',
                'groups' => $userData['groups'] ?? [],
                'faculty' => $userData['faculty'] ?? null,
                'student_id' => $userData['student_id'] ?? null,
                'program' => $userData['program'] ?? null,
            ]
        ]);
    }
}
```

### Custom Access Control Based on Attributes

```php
<?php

class CustomAccessMiddleware
{
    public function handle($request, Closure $next, ...$requirements)
    {
        if (!Auth::check()) {
            return redirect('/login');
        }
        
        $samlAttributes = session('saml_session.attributes', []);
        $userData = app(AdfsService::class)->mapAttributes($samlAttributes);
        
        // Check if user is faculty member
        if (in_array('faculty-only', $requirements)) {
            if (!in_array('Faculty', $userData['groups'] ?? [])) {
                abort(403, 'Faculty access required');
            }
        }
        
        // Check if user has specific employee type
        if (in_array('staff-only', $requirements)) {
            if (($userData['employee_type'] ?? '') !== 'Staff') {
                abort(403, 'Staff access required');
            }
        }
        
        // Check department-specific access
        foreach ($requirements as $requirement) {
            if (str_starts_with($requirement, 'dept:')) {
                $requiredDept = substr($requirement, 5);
                if (($userData['department'] ?? '') !== $requiredDept) {
                    abort(403, "Access restricted to {$requiredDept} department");
                }
            }
        }
        
        return $next($request);
    }
}
```

### Route Examples with Enhanced Middleware

```php
// In routes/web.php

// Faculty-only resources
Route::middleware(['adfs.auth', 'custom-access:faculty-only'])->group(function () {
    Route::get('/faculty-resources', 'FacultyController@resources');
    Route::get('/research-tools', 'ResearchController@tools');
});

// Department-specific access
Route::middleware(['adfs.auth', 'custom-access:dept:Mathematics'])->group(function () {
    Route::get('/math-department', 'MathDepartmentController@index');
});

// Staff administrative functions
Route::middleware(['adfs.auth', 'custom-access:staff-only'])->group(function () {
    Route::get('/admin-panel', 'AdminController@index');
});

// Mixed requirements
Route::middleware(['adfs.auth', 'custom-access:faculty-only,dept:Computer Science'])->group(function () {
    Route::get('/cs-faculty-only', 'CSFacultyController@index');
});
```

## Migration Guide

If you're upgrading from the basic version, your existing configuration will continue to work. To take advantage of new features:

1. **Update your config** to include new attributes you want to use
2. **Update your User model** to include new database fields if needed
3. **Update your views** to display additional user information
4. **Test group-based access control** with the enhanced group processing

The package is backward compatible - existing functionality will continue to work unchanged.