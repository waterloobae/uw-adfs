# ADFS SAML Response Examples

This document shows what a typical UW ADFS SAML response looks like when authentication is approved, including all supported attributes.

**Note**: Your UW ADFS package now supports an expanded set of attributes including title, phone, employee ID, office location, and enhanced group processing.

## 1. Raw SAML Response (XML)

When ADFS approves authentication, it sends an HTTP POST to your Assertion Consumer Service (ACS) endpoint with a SAML response like this:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<samlp:Response 
    xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
    xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
    ID="_8e8dc5f69a98cc4c1ff3427e5ce34606fd672f91e6"
    Version="2.0"
    IssueInstant="2025-10-03T14:39:43.060Z"
    Destination="https://your-app.uwaterloo.ca/saml/acs"
    InResponseTo="_be9967abd904ddcae3c0eb4a9c6ac9c16dde20e54a">
    
    <saml:Issuer>https://adfs.uwaterloo.ca/adfs/services/trust</saml:Issuer>
    
    <samlp:Status>
        <samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/>
    </samlp:Status>
    
    <saml:Assertion 
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xmlns:xs="http://www.w3.org/2001/XMLSchema"
        ID="_d71a3a8e9fcc45c9a9d4f4af9ac311604"
        Version="2.0"
        IssueInstant="2025-10-03T14:39:43.050Z">
        
        <saml:Issuer>https://adfs.uwaterloo.ca/adfs/services/trust</saml:Issuer>
        
        <ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
            <!-- Digital signature for security -->
        </ds:Signature>
        
        <saml:Subject>
            <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified">
                jsmith@uwaterloo.ca
            </saml:NameID>
            <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
                <saml:SubjectConfirmationData 
                    NotOnOrAfter="2025-10-03T14:44:43.060Z"
                    Recipient="https://your-app.uwaterloo.ca/saml/acs"
                    InResponseTo="_be9967abd904ddcae3c0eb4a9c6ac9c16dde20e54a"/>
            </saml:SubjectConfirmation>
        </saml:Subject>
        
        <saml:Conditions 
            NotBefore="2025-10-03T14:34:43.050Z"
            NotOnOrAfter="2025-10-03T14:44:43.060Z">
            <saml:AudienceRestriction>
                <saml:Audience>https://your-app.uwaterloo.ca</saml:Audience>
            </saml:AudienceRestriction>
        </saml:Conditions>
        
        <saml:AuthnStatement AuthnInstant="2025-10-03T14:39:43.050Z">
            <saml:AuthnContext>
                <saml:AuthnContextClassRef>
                    urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport
                </saml:AuthnContextClassRef>
            </saml:AuthnContext>
        </saml:AuthnStatement>
        
        <saml:AttributeStatement>
            <!-- User's email address -->
            <saml:Attribute Name="http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress">
                <saml:AttributeValue xsi:type="xs:string">jsmith@uwaterloo.ca</saml:AttributeValue>
            </saml:Attribute>
            
            <!-- User's display name -->
            <saml:Attribute Name="http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name">
                <saml:AttributeValue xsi:type="xs:string">John Smith</saml:AttributeValue>
            </saml:Attribute>
            
            <!-- User's first name -->
            <saml:Attribute Name="http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname">
                <saml:AttributeValue xsi:type="xs:string">John</saml:AttributeValue>
            </saml:Attribute>
            
            <!-- User's last name -->
            <saml:Attribute Name="http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname">
                <saml:AttributeValue xsi:type="xs:string">Smith</saml:AttributeValue>
            </saml:Attribute>
            
            <!-- User's department -->
            <saml:Attribute Name="http://schemas.xmlsoap.org/ws/2005/05/identity/claims/department">
                <saml:AttributeValue xsi:type="xs:string">Mathematics</saml:AttributeValue>
            </saml:Attribute>
            
            <!-- User's job title -->
            <saml:Attribute Name="http://schemas.xmlsoap.org/ws/2005/05/identity/claims/title">
                <saml:AttributeValue xsi:type="xs:string">Professor</saml:AttributeValue>
            </saml:Attribute>
            
            <!-- User's phone number -->
            <saml:Attribute Name="http://schemas.xmlsoap.org/ws/2005/05/identity/claims/phone">
                <saml:AttributeValue xsi:type="xs:string">519-888-4567</saml:AttributeValue>
            </saml:Attribute>
            
            <!-- User's groups/roles (multiple values possible) -->
            <saml:Attribute Name="http://schemas.xmlsoap.org/ws/2005/05/identity/claims/memberof">
                <saml:AttributeValue xsi:type="xs:string">CN=Faculty,OU=Groups,DC=uwaterloo,DC=ca</saml:AttributeValue>
                <saml:AttributeValue xsi:type="xs:string">CN=Mathematics Faculty,OU=Groups,DC=uwaterloo,DC=ca</saml:AttributeValue>
                <saml:AttributeValue xsi:type="xs:string">CN=CEMC Members,OU=Groups,DC=uwaterloo,DC=ca</saml:AttributeValue>
            </saml:Attribute>
            
            <!-- User's employee ID -->
            <saml:Attribute Name="http://schemas.xmlsoap.org/ws/2005/05/identity/claims/employeeid">
                <saml:AttributeValue xsi:type="xs:string">123456789</saml:AttributeValue>
            </saml:Attribute>
            
            <!-- User's student/employee type -->
            <saml:Attribute Name="http://schemas.xmlsoap.org/ws/2005/05/identity/claims/employeetype">
                <saml:AttributeValue xsi:type="xs:string">Faculty</saml:AttributeValue>
            </saml:Attribute>
        </saml:AttributeStatement>
    </saml:Assertion>
</samlp:Response>
```

## 2. Processed SAML Data (PHP Array)

After the OneLogin SAML library processes the XML response, your application receives this data structure:

```php
// In AdfsController::acs(), $samlData looks like this:
$samlData = [
    'nameId' => 'jsmith@uwaterloo.ca',
    'sessionIndex' => '_8e8dc5f69a98cc4c1ff3427e5ce34606fd672f91e6',
    'attributes' => [
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress' => [
            'jsmith@uwaterloo.ca'
        ],
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name' => [
            'John Smith'
        ],
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname' => [
            'John'
        ],
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname' => [
            'Smith'
        ],
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/department' => [
            'Mathematics'
        ],
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/title' => [
            'Professor'
        ],
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/phone' => [
            '519-888-4567'
        ],
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/memberof' => [
            'CN=Faculty,OU=Groups,DC=uwaterloo,DC=ca',
            'CN=Mathematics Faculty,OU=Groups,DC=uwaterloo,DC=ca',
            'CN=CEMC Members,OU=Groups,DC=uwaterloo,DC=ca'
        ],
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/employeeid' => [
            '123456789'
        ],
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/employeetype' => [
            'Faculty'
        ]
    ]
];
```

## 3. Mapped User Data

The UW ADFS package now maps these attributes to cleaner array keys for easier use:

```php
// After AdfsService::mapAttributes(), you get:
$userData = [
    // Core identity
    'email' => 'jsmith@uwaterloo.ca',
    'name' => 'John Smith',
    'first_name' => 'John',
    'last_name' => 'Smith',
    
    // Organizational information
    'department' => 'Mathematics',
    'title' => 'Professor',
    'employee_id' => '123456789',
    'employee_type' => 'Faculty',
    
    // Contact information
    'phone' => '519-888-4567',
    'office' => 'MC 6426',
    
    // Groups (automatically cleaned from DN format)
    'groups' => [
        'Faculty',                    // from CN=Faculty,OU=Groups,DC=uwaterloo,DC=ca
        'Mathematics Faculty',        // from CN=Mathematics Faculty,OU=Groups,DC=uwaterloo,DC=ca
        'CEMC Members'               // from CN=CEMC Members,OU=Groups,DC=uwaterloo,DC=ca
    ],
    
    // Additional UW-specific attributes (if available)
    'student_id' => null,            // Only for students
    'faculty' => 'Mathematics',      // Faculty affiliation
    'program' => null                // Student program (if applicable)
];
```

**Enhanced Features:**
- **Multiple Group Attribute Support**: Tries multiple possible SAML claim names for groups
- **Automatic Group Cleanup**: Extracts clean group names from Distinguished Name format
- **Comprehensive Attribute Mapping**: Supports all common UW ADFS attributes
- **Fallback Support**: Uses the first available attribute from multiple possible names

## 4. Session Data

After successful authentication, this data is stored in the Laravel session:

```php
// Session::get('saml_session') returns:
[
    'nameId' => 'jsmith@uwaterloo.ca',
    'sessionIndex' => '_8e8dc5f69a98cc4c1ff3427e5ce34606fd672f91e6',
    'attributes' => [
        // Full SAML attributes array as shown above
    ],
    'access_control_result' => [
        'authorized' => true,
        'reason' => 'Access granted',
        'checks' => [
            'whitelist' => ['status' => 'not_checked', 'reason' => 'Whitelist disabled'],
            'blocked_groups' => ['status' => 'passed', 'reason' => 'No blocked groups found'],
            'required_groups' => ['status' => 'passed', 'reason' => 'User belongs to required group: Faculty'],
            'department' => ['status' => 'passed', 'reason' => 'User department Mathematics is allowed']
        ]
    ]
]
```

## 5. Access Control Processing

The `AccessControlService` evaluates the user's attributes against your configuration:

```php
// Access control decision for this user:
$accessResult = [
    'authorized' => true,
    'reason' => 'Access granted',
    'checks' => [
        'whitelist' => [
            'status' => 'not_checked',
            'reason' => 'Whitelist disabled'
        ],
        'blocked_groups' => [
            'status' => 'passed',
            'reason' => 'No blocked groups found',
            'user_groups' => ['Faculty', 'Mathematics Faculty', 'CEMC Members'],
            'blocked_groups' => []
        ],
        'required_groups' => [
            'status' => 'passed',
            'reason' => 'User belongs to required group: Faculty',
            'user_groups' => ['Faculty', 'Mathematics Faculty', 'CEMC Members'],
            'required_groups' => ['Faculty', 'Staff', 'Graduate Students']
        ],
        'department' => [
            'status' => 'passed',
            'reason' => 'User department Mathematics is allowed',
            'user_department' => 'Mathematics',
            'allowed_departments' => ['Mathematics', 'Statistics']
        ]
    ]
];
```

## 6. HTTP Request Details

The SAML response arrives as an HTTP POST request to your ACS endpoint:

```
POST /saml/acs HTTP/1.1
Host: your-app.uwaterloo.ca
Content-Type: application/x-www-form-urlencoded
Content-Length: 8542

SAMLResponse=PHNhbWxwOlJlc3BvbnNlIHhtbG5zOnNhbWxwPSJ1cm46b2FzaXM6bmFtZXM6dGM6...
&RelayState=https%3A%2F%2Fyour-app.uwaterloo.ca%2Fdashboard
```

Where:
- `SAMLResponse` is the base64-encoded XML response shown above
- `RelayState` is the URL to redirect to after successful authentication (optional)

## 7. Response Validation

The OneLogin SAML library automatically validates:

1. **Digital Signature**: Ensures the response came from UW ADFS
2. **Timestamps**: Checks NotBefore and NotOnOrAfter conditions
3. **Audience**: Verifies the response is intended for your application
4. **Recipient**: Confirms the ACS URL matches your configuration
5. **InResponseTo**: Matches the original authentication request ID

## 8. Error Responses

If authentication fails, ADFS sends a different response:

```xml
<samlp:Response>
    <saml:Issuer>https://adfs.uwaterloo.ca/adfs/services/trust</saml:Issuer>
    <samlp:Status>
        <samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Responder"/>
        <samlp:StatusMessage>Authentication failed</samlp:StatusMessage>
    </samlp:Status>
</samlp:Response>
```

## 9. Debugging Tips

To see the actual SAML response in your application:

1. Visit `/saml/attributes` after logging in to see processed attributes
2. Add logging in `AdfsController::acs()`:
   ```php
   Log::info('SAML Response', $samlData);
   ```
3. Enable SAML debugging in OneLogin:
   ```php
   $auth = new OneLogin\Saml2\Auth($samlSettings);
   $auth->setDebug(true);
   ```

This structure allows your application to authenticate users, extract their attributes, apply access control rules, and maintain session state for subsequent requests.