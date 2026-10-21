<?php
/**
 * CompanyProfile.php - company_profiles (one row per user). See UserSettingsRow for the query rules.
 *
 */
declare(strict_types=1);

namespace Src\Models;

final class CompanyProfile extends UserSettingsRow
{
    protected function table(): string
    {
        return 'company_profiles';
    }

    protected function writable(): array
    {
        return ['company_name', 'company_logo_url', 'company_background', 'company_description', 'business_category',
                'payment_instructions', 'payment_qr_url'];
    }
}
