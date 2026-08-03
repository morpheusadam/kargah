<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;

/**
 * The companies and people the rest of Kargah hangs off.
 *
 * Keyed on stable natural keys — a company's name, a customer's email — so
 * running this twice leaves the database exactly as the first run did. Every
 * seeder in this project has to hold that property: they run from the deploy
 * script, and a deploy that duplicates the client list is a bad afternoon.
 */
class CoreDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $companies = [
            [
                'name' => 'Northwind Ltd',
                'legal_name' => 'Northwind Trading Limited',
                'tax_number' => 'GB 771 4402 09',
                'country' => 'GB',
                'address' => "42 Bevis Marks\nLondon EC3A 7BA\nUnited Kingdom",
                'default_currency' => 'USD',
                'is_domestic' => false,
                'website' => 'northwind.example',
                'notes' => 'Procurement pay on the 15th and the last working day only. Invoice before the 10th.',
            ],
            [
                'name' => 'Acme Studio',
                'legal_name' => 'Acme Studio B.V.',
                'tax_number' => 'NL 8123 45 678 B01',
                'country' => 'NL',
                'address' => "Prinsengracht 263\n1016 GV Amsterdam\nNetherlands",
                'default_currency' => 'USD',
                'is_domestic' => false,
                'website' => 'acmestudio.example',
                'notes' => 'Wants to move off Postmark next year — keep the provider layer swappable.',
            ],
            [
                'name' => 'Bluepeak',
                'legal_name' => null,
                'tax_number' => null,
                'country' => 'GB',
                'address' => "11 Trinity Row\nBristol BS2 0AA\nUnited Kingdom",
                'default_currency' => 'USD',
                'is_domestic' => false,
                'website' => 'bluepeak.example',
                'notes' => 'Referred Orbit Studio. Worth a thank-you when the booking widget ships.',
            ],
            [
                'name' => 'Harbour & Finch',
                'legal_name' => 'Harbour ve Finch Danışmanlık A.Ş.',
                'tax_number' => '4560123789',
                'tax_office' => 'Beşiktaş',
                'country' => 'TR',
                'address' => "Büyükdere Caddesi 122\n34394 Şişli, İstanbul\nTürkiye",
                'default_currency' => 'TRY',
                'is_domestic' => true,
                'website' => 'harbourfinch.example',
                'notes' => 'Domestic Turkish buyer: a foreign-currency invoice must carry the TCMB buying rate and the lira equivalent.',
            ],
        ];

        foreach ($companies as $attributes) {
            Company::query()->updateOrCreate(
                ['name' => $attributes['name']],
                $attributes,
            );
        }

        $customers = [
            [
                'email' => 'sam@northwind.example',
                'company' => 'Northwind Ltd',
                'name' => 'Sam Okafor',
                'role' => 'Head of Product',
                'phone' => '+44 20 7946 0918',
                'timezone' => 'Europe/London',
                'notes' => 'Wants the analytics dashboard scoped before the new financial year.',
            ],
            [
                'email' => 'helen@northwind.example',
                'company' => 'Northwind Ltd',
                'name' => 'Helen Vasquez',
                'role' => 'Finance Manager',
                'phone' => '+44 20 7946 0922',
                'timezone' => 'Europe/London',
                'notes' => 'The retainer proposal goes to Helen, not Sam.',
            ],
            [
                'email' => 'joris@acmestudio.example',
                'company' => 'Acme Studio',
                'name' => 'Joris Bakker',
                'role' => 'Technical Director',
                'phone' => '+31 20 262 0410',
                'timezone' => 'Europe/Amsterdam',
                'notes' => 'Reviews every pull request personally. Expect slow merges in August.',
            ],
            [
                'email' => 'priya@bluepeak.example',
                'company' => 'Bluepeak',
                'name' => 'Priya Nandakumar',
                'role' => 'Founder',
                'phone' => '+44 117 496 0031',
                'timezone' => 'Europe/London',
                'notes' => 'Booking widget has to work without a build step on their existing site.',
            ],
            [
                'email' => 'deniz@harbourfinch.example',
                'company' => 'Harbour & Finch',
                'name' => 'Deniz Aydın',
                'role' => 'Operations Lead',
                'phone' => '+90 212 705 4180',
                'timezone' => 'Europe/Istanbul',
                'notes' => 'Invoices must show the lira equivalent. Their accountant checks the rate date.',
            ],
            [
                'email' => 'marta@orbitstudio.example',
                'company' => null,
                'name' => 'Marta Lindqvist',
                'role' => 'Independent producer',
                'phone' => '+46 8 505 20 100',
                'timezone' => 'Europe/Stockholm',
                'notes' => 'Referred by Bluepeak. No company yet — invoices go to her personally.',
            ],
        ];

        foreach ($customers as $attributes) {
            $companyName = $attributes['company'];
            unset($attributes['company']);

            $attributes['company_id'] = $companyName === null
                ? null
                : Company::query()->where('name', $companyName)->value('id');

            Customer::query()->updateOrCreate(
                ['email' => $attributes['email']],
                $attributes,
            );
        }
    }
}
