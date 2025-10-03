<?php

namespace WaterlooBae\UwAdfs\Services;

use Illuminate\Support\Facades\Log;

class AccessControlService
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Check if a user is authorized based on SAML attributes
     */
    public function isUserAuthorized(array $samlAttributes): array
    {
        $result = [
            'authorized' => false,
            'reason' => '',
            'checks' => []
        ];

        // Extract user information from SAML attributes
        $email = $this->getAttribute($samlAttributes, 'email') ?? '';
        $department = $this->getAttribute($samlAttributes, 'department');
        $groups = $this->getAttribute($samlAttributes, 'groups', true);        // 1. Check whitelist first (if enabled, this overrides other restrictions)
        if ($this->config['whitelist_enabled']) {
            $whitelistCheck = $this->checkWhitelist($email);
            $result['checks']['whitelist'] = $whitelistCheck;
            
            if ($whitelistCheck['passed']) {
                $result['authorized'] = true;
                $result['reason'] = 'User is on the whitelist';
                return $result;
            }
            
            // If whitelist is enabled but user not on it, check other rules
            // (unless whitelist is exclusive)
            if (!empty($this->config['whitelist_emails'])) {
                $result['authorized'] = false;
                $result['reason'] = 'User not on whitelist';
                return $result;
            }
        }

        // 2. Check blocked groups
        if ($this->config['group_restriction_enabled'] && !empty($this->config['blocked_groups'])) {
            $blockedGroupCheck = $this->checkBlockedGroups($groups);
            $result['checks']['blocked_groups'] = $blockedGroupCheck;
            
            if (!$blockedGroupCheck['passed']) {
                $result['authorized'] = false;
                $result['reason'] = $blockedGroupCheck['reason'];
                return $result;
            }
        }

        // 3. Check required groups
        if ($this->config['group_restriction_enabled'] && !empty($this->config['required_groups'])) {
            $requiredGroupCheck = $this->checkRequiredGroups($groups);
            $result['checks']['required_groups'] = $requiredGroupCheck;
            
            if (!$requiredGroupCheck['passed']) {
                $result['authorized'] = false;
                $result['reason'] = $requiredGroupCheck['reason'];
                return $result;
            }
        }

        // 4. Check department restrictions
        if ($this->config['department_restriction_enabled'] && !empty($this->config['allowed_departments'])) {
            $departmentCheck = $this->checkDepartment($department);
            $result['checks']['department'] = $departmentCheck;
            
            if (!$departmentCheck['passed']) {
                $result['authorized'] = false;
                $result['reason'] = $departmentCheck['reason'];
                return $result;
            }
        }

        // If we get here, user is authorized
        $result['authorized'] = true;
        $result['reason'] = 'User passed all access control checks';

        return $result;
    }

    /**
     * Check if email is on whitelist
     */
    protected function checkWhitelist(string $email): array
    {
        $whitelistEmails = $this->config['whitelist_emails'] ?? [];
        
        if (empty($whitelistEmails)) {
            return ['passed' => true, 'reason' => 'No whitelist configured'];
        }

        $isWhitelisted = in_array(strtolower($email), array_map('strtolower', $whitelistEmails));
        
        return [
            'passed' => $isWhitelisted,
            'reason' => $isWhitelisted ? 'Email is whitelisted' : 'Email not on whitelist',
            'checked_email' => $email,
            'whitelist' => $whitelistEmails
        ];
    }

    /**
     * Check if user belongs to blocked groups
     */
    protected function checkBlockedGroups(array $userGroups): array
    {
        $blockedGroups = $this->config['blocked_groups'] ?? [];
        
        if (empty($blockedGroups)) {
            return ['passed' => true, 'reason' => 'No blocked groups configured'];
        }

        $userGroupsLower = array_map('strtolower', $userGroups);
        $blockedGroupsLower = array_map('strtolower', $blockedGroups);
        
        $intersection = array_intersect($userGroupsLower, $blockedGroupsLower);
        
        if (!empty($intersection)) {
            return [
                'passed' => false,
                'reason' => 'User belongs to blocked groups: ' . implode(', ', $intersection),
                'blocked_groups_found' => $intersection
            ];
        }

        return [
            'passed' => true,
            'reason' => 'User does not belong to any blocked groups',
            'user_groups' => $userGroups,
            'blocked_groups' => $blockedGroups
        ];
    }

    /**
     * Check if user belongs to required groups
     */
    protected function checkRequiredGroups(array $userGroups): array
    {
        $requiredGroups = $this->config['required_groups'] ?? [];
        
        if (empty($requiredGroups)) {
            return ['passed' => true, 'reason' => 'No required groups configured'];
        }

        $userGroupsLower = array_map('strtolower', $userGroups);
        $requiredGroupsLower = array_map('strtolower', $requiredGroups);
        
        $intersection = array_intersect($userGroupsLower, $requiredGroupsLower);
        
        if (empty($intersection)) {
            return [
                'passed' => false,
                'reason' => 'User must belong to at least one of: ' . implode(', ', $requiredGroups),
                'user_groups' => $userGroups,
                'required_groups' => $requiredGroups
            ];
        }

        return [
            'passed' => true,
            'reason' => 'User belongs to required groups: ' . implode(', ', $intersection),
            'matched_groups' => $intersection
        ];
    }

    /**
     * Check if user's department is allowed
     */
    protected function checkDepartment(?string $department): array
    {
        $allowedDepartments = $this->config['allowed_departments'] ?? [];
        
        if (empty($allowedDepartments)) {
            return ['passed' => true, 'reason' => 'No department restrictions configured'];
        }

        if (empty($department)) {
            return [
                'passed' => false,
                'reason' => 'No department information provided by ADFS',
                'allowed_departments' => $allowedDepartments
            ];
        }

        $departmentLower = strtolower(trim($department));
        $allowedDepartmentsLower = array_map('strtolower', array_map('trim', $allowedDepartments));
        
        $isAllowed = in_array($departmentLower, $allowedDepartmentsLower);
        
        return [
            'passed' => $isAllowed,
            'reason' => $isAllowed 
                ? "Department '{$department}' is allowed" 
                : "Department '{$department}' is not in allowed list: " . implode(', ', $allowedDepartments),
            'user_department' => $department,
            'allowed_departments' => $allowedDepartments
        ];
    }

    /**
     * Extract attribute value from SAML attributes
     */
    protected function getAttribute(array $samlAttributes, string $attributeName, bool $isArray = false)
    {
        $mapping = config('uw-adfs.attribute_mapping', []);
        $samlAttributeConfig = $mapping[$attributeName] ?? null;
        
        if (!$samlAttributeConfig) {
            return $isArray ? [] : null;
        }
        
        // Handle multiple possible attribute names
        $possibleAttributes = is_array($samlAttributeConfig) ? $samlAttributeConfig : [$samlAttributeConfig];
        
        foreach ($possibleAttributes as $samlAttributeName) {
            if (isset($samlAttributes[$samlAttributeName]) && !empty($samlAttributes[$samlAttributeName])) {
                $value = $samlAttributes[$samlAttributeName];
                
                // Special handling for groups
                if ($attributeName === 'groups') {
                    return $this->processGroupsForAccessControl($value);
                }
                
                if ($isArray) {
                    return is_array($value) ? $value : [$value];
                }
                
                return is_array($value) ? $value[0] : $value;
            }
        }
        
        return $isArray ? [] : null;
    }

    /**
     * Process groups for access control (extract clean group names)
     */
    protected function processGroupsForAccessControl($groups): array
    {
        if (!is_array($groups)) {
            $groups = [$groups];
        }
        
        $cleanGroups = [];
        foreach ($groups as $group) {
            // Extract group name from Distinguished Name format
            if (preg_match('/^CN=([^,]+),/', $group, $matches)) {
                $cleanGroups[] = $matches[1];
            } else {
                $cleanGroups[] = $group;
            }
        }
        
        return array_unique($cleanGroups);
    }    /**
     * Log access control decision
     */
    public function logAccessDecision(string $email, array $result): void
    {
        $level = $result['authorized'] ? 'info' : 'warning';
        
        Log::log($level, 'UW ADFS Access Control Decision', [
            'email' => $email,
            'authorized' => $result['authorized'],
            'reason' => $result['reason'],
            'checks' => $result['checks'] ?? []
        ]);
    }
}