<?php
/**
 * company.php - who our invoices are issued by.
 *
 * This is the ONLY place the issuer details live. Edit the values below and
 * every invoice (screen, print and "Save as PDF") follows immediately.
 *
 * Replace the placeholders in `reg_no`, `address` and the phone numbers with
 * the real SSM registration and business address before sending anything to a
 * customer.
 */
declare(strict_types=1);

return [
    'name'    => 'SME SMART SDN BHD',
    'reg_no'  => '202601000000 (0000000-X)',   // SSM registration number
    'address' => "No. 12, Jalan Teknologi 3/1\nTaman Sains Selangor\n47810 Petaling Jaya, Selangor\nMalaysia",
    'tel'     => '+60 3-0000 0000',
    'fax'     => '',
    'email'   => 'hello@smesmart.my',
    'website' => 'www.smesmart.my',
    'logo'    => 'assets/gemini_logo.png',
];
