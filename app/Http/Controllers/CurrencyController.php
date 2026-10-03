<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyController extends Controller
{
    /**
     * List of supported currencies with symbols, names, and flags.
     */
    private static $currencies = [
        'TZS' => [
            'code' => 'TZS',
            'name' => 'Tanzanian Shilling',
            'symbol' => 'TSh',
            'flag' => '🇹🇿',
            'country' => 'Tanzania',
            'popular' => true,
            'decimals' => 0
        ],
        'USD' => [
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'flag' => '🇺🇸',
            'country' => 'United States',
            'popular' => true,
            'decimals' => 2
        ],
        'EUR' => [
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => '€',
            'flag' => '🇪🇺',
            'country' => 'European Union',
            'popular' => true,
            'decimals' => 2
        ],
        'GBP' => [
            'code' => 'GBP',
            'name' => 'British Pound',
            'symbol' => '£',
            'flag' => '🇬🇧',
            'country' => 'United Kingdom',
            'popular' => true,
            'decimals' => 2
        ],
        'KES' => [
            'code' => 'KES',
            'name' => 'Kenyan Shilling',
            'symbol' => 'KSh',
            'flag' => '🇰🇪',
            'country' => 'Kenya',
            'popular' => true,
            'decimals' => 2
        ],
        'UGX' => [
            'code' => 'UGX',
            'name' => 'Ugandan Shilling',
            'symbol' => 'USh',
            'flag' => '🇺🇬',
            'country' => 'Uganda',
            'popular' => true,
            'decimals' => 0
        ],
        'RWF' => [
            'code' => 'RWF',
            'name' => 'Rwandan Franc',
            'symbol' => 'FRw',
            'flag' => '🇷🇼',
            'country' => 'Rwanda',
            'popular' => false,
            'decimals' => 0
        ],
        'ZAR' => [
            'code' => 'ZAR',
            'name' => 'South African Rand',
            'symbol' => 'R',
            'flag' => '🇿🇦',
            'country' => 'South Africa',
            'popular' => true,
            'decimals' => 2
        ],
        'AED' => [
            'code' => 'AED',
            'name' => 'UAE Dirham',
            'symbol' => 'AED',
            'flag' => '🇦🇪',
            'country' => 'United Arab Emirates',
            'popular' => true,
            'decimals' => 2
        ],
        'SAR' => [
            'code' => 'SAR',
            'name' => 'Saudi Riyal',
            'symbol' => 'SAR',
            'flag' => '🇸🇦',
            'country' => 'Saudi Arabia',
            'popular' => false,
            'decimals' => 2
        ],
        'CAD' => [
            'code' => 'CAD',
            'name' => 'Canadian Dollar',
            'symbol' => 'CA$',
            'flag' => '🇨🇦',
            'country' => 'Canada',
            'popular' => false,
            'decimals' => 2
        ],
        'AUD' => [
            'code' => 'AUD',
            'name' => 'Australian Dollar',
            'symbol' => 'A$',
            'flag' => '🇦🇺',
            'country' => 'Australia',
            'popular' => false,
            'decimals' => 2
        ],
        'INR' => [
            'code' => 'INR',
            'name' => 'Indian Rupee',
            'symbol' => '₹',
            'flag' => '🇮🇳',
            'country' => 'India',
            'popular' => true,
            'decimals' => 2
        ],
        'CNY' => [
            'code' => 'CNY',
            'name' => 'Chinese Yuan',
            'symbol' => '¥',
            'flag' => '🇨🇳',
            'country' => 'China',
            'popular' => true,
            'decimals' => 2
        ],
        'JPY' => [
            'code' => 'JPY',
            'name' => 'Japanese Yen',
            'symbol' => '¥',
            'flag' => '🇯🇵',
            'country' => 'Japan',
            'popular' => false,
            'decimals' => 0
        ],
        'CHF' => [
            'code' => 'CHF',
            'name' => 'Swiss Franc',
            'symbol' => 'CHF',
            'flag' => '🇨🇭',
            'country' => 'Switzerland',
            'popular' => false,
            'decimals' => 2
        ],
        'TRY' => [
            'code' => 'TRY',
            'name' => 'Turkish Lira',
            'symbol' => '₺',
            'flag' => '🇹🇷',
            'country' => 'Turkey',
            'popular' => false,
            'decimals' => 2
        ],
        'NGN' => [
            'code' => 'NGN',
            'name' => 'Nigerian Naira',
            'symbol' => '₦',
            'flag' => '🇳🇬',
            'country' => 'Nigeria',
            'popular' => false,
            'decimals' => 2
        ],
        'GHS' => [
            'code' => 'GHS',
            'name' => 'Ghanaian Cedi',
            'symbol' => 'GH₵',
            'flag' => '🇬🇭',
            'country' => 'Ghana',
            'popular' => false,
            'decimals' => 2
        ],
        'EGP' => [
            'code' => 'EGP',
            'name' => 'Egyptian Pound',
            'symbol' => 'E£',
            'flag' => '🇪🇬',
            'country' => 'Egypt',
            'popular' => false,
            'decimals' => 2
        ],
        'SGD' => [
            'code' => 'SGD',
            'name' => 'Singapore Dollar',
            'symbol' => 'S$',
            'flag' => '🇸🇬',
            'country' => 'Singapore',
            'popular' => false,
            'decimals' => 2
        ],
        'MYR' => [
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'symbol' => 'RM',
            'flag' => '🇲🇾',
            'country' => 'Malaysia',
            'popular' => false,
            'decimals' => 2
        ],
        'THB' => [
            'code' => 'THB',
            'name' => 'Thai Baht',
            'symbol' => '฿',
            'flag' => '🇹🇭',
            'country' => 'Thailand',
            'popular' => false,
            'decimals' => 2
        ]
    ];

    /**
     * Default fallback exchange rates relative to USD (1 USD = X currency)
     */
    private static $fallbackUsdRates = [
        'USD' => 1.0,
        'TZS' => 2630.0,
        'EUR' => 0.92,
        'GBP' => 0.79,
        'KES' => 132.0,
        'UGX' => 3720.0,
        'RWF' => 1340.0,
        'ZAR' => 18.2,
        'AED' => 3.67,
        'SAR' => 3.75,
        'CAD' => 1.36,
        'AUD' => 1.52,
        'INR' => 83.5,
        'CNY' => 7.24,
        'JPY' => 155.0,
        'CHF' => 0.91,
        'TRY' => 32.5,
        'NGN' => 1480.0,
        'GHS' => 14.8,
        'EGP' => 47.8,
        'SGD' => 1.35,
        'MYR' => 4.71,
        'THB' => 36.6
    ];

    /**
     * Get all supported currencies with live exchange rates.
     */
    public function index()
    {
        $rates = $this->getLiveExchangeRates();

        // Calculate rate relative to TZS (Base currency)
        $tzsUsdRate = $rates['TZS'] ?? 2630.0;

        $currencyList = [];
        foreach (self::$currencies as $code => $info) {
            $usdRate = $rates[$code] ?? (self::$fallbackUsdRates[$code] ?? 1.0);
            
            // 1 TZS = how many of target currency
            $rateFromTzs = $usdRate / $tzsUsdRate;

            // 1 Unit of target currency = how many TZS
            $tzsPerUnit = $rateFromTzs > 0 ? (1 / $rateFromTzs) : 1;

            $currencyList[] = array_merge($info, [
                'rate_usd' => round($usdRate, 6),
                'rate_from_tzs' => $rateFromTzs,
                'tzs_per_unit' => round($tzsPerUnit, 2)
            ]);
        }

        return response()->json([
            'status' => 'success',
            'base_currency' => 'TZS',
            'currencies' => $currencyList,
            // Lets the UI tell the guest the rates are approximate rather than
            // presenting a hardcoded table as a live quote.
            'rates_stale' => $rates === self::$fallbackUsdRates,
            'updated_at' => now()->toIso8601String()
        ]);
    }

    /**
     * Get live exchange rates with 24-hour caching and fallback resilience.
     */
    private function getLiveExchangeRates()
    {
        return Cache::remember('global_exchange_rates_usd', 86400, function () {
            try {
                // Free, open, zero-auth API
                $res = Http::timeout(4)->get('https://open.er-api.com/v6/latest/USD');
                if ($res->ok()) {
                    $data = $res->json();
                    if (isset($data['rates']) && is_array($data['rates'])) {
                        // Live rates win outright. Previously the hardcoded
                        // fallback table was merged underneath, so any currency
                        // missing from a partial live response silently kept a
                        // months-old rate and the response looked healthy.
                        return $data['rates'];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Exchange rate API unavailable, using fallback: ' . $e->getMessage());
            }

            return self::$fallbackUsdRates;
        });
    }
}
