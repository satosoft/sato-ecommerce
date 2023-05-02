<?php

use Carbon\Carbon;
use App\Models\Cart;
use App\Models\City;
use App\Models\Shop;
use App\Models\User;
use App\Models\Addon;
use App\Models\Coupon;
use App\Models\Seller;
use App\Models\Upload;
use App\Models\Wallet;
use App\Models\Address;
use App\Models\Carrier;
use App\Models\Country;
use App\Models\Product;
use App\Models\Currency;
use App\Models\CouponUsage;
use App\Models\Translation;
use App\Models\ProductStock;
use App\Models\CombinedOrder;
use App\Models\SellerPackage;
use App\Models\BusinessSetting;
use App\Models\CustomerPackage;
use App\Utility\SendSMSUtility;
use App\Utility\CategoryUtility;
use App\Models\SellerPackagePayment;
use App\Utility\NotificationUtility;
use App\Http\Resources\V2\CarrierCollection;
use App\Http\Controllers\Admin\AffiliateController;
use App\Http\Controllers\ClubPointController;
use App\Http\Controllers\CommissionController;

//filter products based on vendor activation system
function areActiveRoutes(array $routes, $output = "active")
{
    foreach ($routes as $route) {
        if (Route::currentRouteName() == $route) return $output;
    }
}

function areActiveRoutesHome(array $routes, $output = "active")
{
    foreach ($routes as $route) {
        if (Route::currentRouteName() == $route) return $output;
    }
}

function default_language()
{
    return env("DEFAULT_LANGUAGE");
}

function filter_products($products)
{
    $verified_sellers = verified_sellers_id();
    if (get_setting('vendor_system_activation') == 1) {
        return $products->where('approved', '1')
            ->where('published', '1')
            ->where('auction_product', 0)
            ->where(function ($p) use ($verified_sellers) {
                $p->where('added_by', 'admin')->orWhere(function ($q) use ($verified_sellers) {
                    $q->whereIn('user_id', $verified_sellers);
                });
            });
    } else {
        return $products->where('published', '1')->where('auction_product', 0)->where('added_by', 'admin');
    }
}

function verified_sellers_id()
{
    return Cache::rememberForever('verified_sellers_id', function () {
        return App\Models\Shop::where('verification_status', 1)->pluck('user_id')->toArray();
    });
}

function get_setting($key, $default = null, $lang = false)
{
    $settings = BusinessSetting::all();

    if ($lang == false) {
        $setting = $settings->where('type', $key)->first();
    } else {
        $setting = $settings->where('type', $key)->where('lang', $lang)->first();
        $setting = !$setting ? $settings->where('type', $key)->first() : $setting;
    }
    return $setting == null ? $default : $setting->value;
}


function static_asset($path, $secure = null)
{
        //return app('url')->asset('public/' . $path, $secure);
        return app('url')->asset( $path, $secure);
}



function uploaded_asset($id)
{
    if (($asset = \App\Models\Upload::find($id)) != null) {
        return $asset->external_link == null ? my_asset($asset->file_name) : $asset->external_link;
    }
    return static_asset('assets/img/placeholder.jpg');
}



function my_asset($path, $secure = null){
    if (env('FILESYSTEM_DRIVER') == 's3') {
        return Storage::disk('s3')->url($path);
    } else {
        //return app('url')->asset('public/' . $path, $secure);
        return app('url')->asset($path, $secure);
    }
}


function translate($key, $lang = null, $addslashes = false)
{
    if ($lang == null) {
        $lang = App::getLocale();
    }

    $lang_key = preg_replace('/[^A-Za-z0-9\_]/', '', str_replace(' ', '_', strtolower($key)));

    $translations_en = Cache::rememberForever('translations-en', function () {
        return Translation::where('lang', 'en')->pluck('lang_value', 'lang_key')->toArray();
    });

    if (!isset($translations_en[$lang_key])) {
        $translation_def = new Translation;
        $translation_def->lang = 'en';
        $translation_def->lang_key = $lang_key;
        $translation_def->lang_value = str_replace(array("\r", "\n", "\r\n"), "", $key);
        $translation_def->save();
        Cache::forget('translations-en');
    }

    // return user session lang
    $translation_locale = Cache::rememberForever("translations-{$lang}", function () use ($lang) {
        return Translation::where('lang', $lang)->pluck('lang_value', 'lang_key')->toArray();
    });
    if (isset($translation_locale[$lang_key])) {
        return $addslashes ? addslashes(trim($translation_locale[$lang_key])) : trim($translation_locale[$lang_key]);
    }

    // return default lang if session lang not found
    $translations_default = Cache::rememberForever('translations-' . env('DEFAULT_LANGUAGE', 'en'), function () {
        return Translation::where('lang', env('DEFAULT_LANGUAGE', 'en'))->pluck('lang_value', 'lang_key')->toArray();
    });
    if (isset($translations_default[$lang_key])) {
        return $addslashes ? addslashes(trim($translations_default[$lang_key])) : trim($translations_default[$lang_key]);
    }

    // fallback to en lang
    if (!isset($translations_en[$lang_key])) {
        return trim($key);
    }
    return $addslashes ? addslashes(trim($translations_en[$lang_key])) : trim($translations_en[$lang_key]);
}

// Addon Activation Check

function addon_is_activated($identifier, $default = null)
    {
        $addons = Cache::remember('addons', 86400, function () {
            return Addon::all();
        });

        $activation = $addons->where('unique_identifier', $identifier)->where('activated', 1)->first();
        return $activation == null ? false : true;
    }




function getBaseURL()
    {
        $root = '//' . $_SERVER['HTTP_HOST'];
        $root .= str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME']);

        return $root;
    }




function getFileBaseURL()
    {
        if (env('FILESYSTEM_DRIVER') == 's3') {
            return env('AWS_URL') . '/';
        } else {
            //return getBaseURL() . 'public/';
            return getBaseURL();
        }
    }


function hex2rgba($color, $opacity = false){
    return App\Lib\Colorcodeconverter::convertHexToRgba($color, $opacity);
    }


function isAdmin()
{
    if (Auth::check() && (Auth::user()->user_type == 'admin' || Auth::user()->user_type == 'staff')) {
        return true;
    }
    return false;
}

function isSeller()
{
    if (Auth::check() && Auth::user()->user_type == 'seller') {
        return true;
    }
    return false;
}


function isCustomer()
{
    if (Auth::check() && Auth::user()->user_type == 'customer') {
        return true;
    }
    return false;
}

function single_price($price)
    {
        return format_price(convert_price($price));
    }

function discount_in_percentage($product)
{
    $base = home_base_price($product, false);
    $reduced = home_discounted_base_price($product, false);
    $discount = $base - $reduced;
    $dp = ($discount * 100) / ($base > 0 ? $base : 1);
    return round($dp);
}

function format_price($price, $isMinimize = false)
{
    if (get_setting('decimal_separator') == 1) {
        $fomated_price = number_format($price, get_setting('no_of_decimals'));
    } else {
        $fomated_price = number_format($price, get_setting('no_of_decimals'), ',', '.');
    }


    // Minimize the price 
    if ($isMinimize) {
        $temp = number_format($price / 1000000000, get_setting('no_of_decimals'), ".", "");

        if ($temp >= 1) {
            $fomated_price = $temp . "B";
        } else {
            $temp = number_format($price / 1000000, get_setting('no_of_decimals'), ".", "");
            if ($temp >= 1) {
                $fomated_price = $temp . "M";
            }
        }
    }

    if (get_setting('symbol_format') == 1) {
        return currency_symbol() . $fomated_price;
    } else if (get_setting('symbol_format') == 3) {
        return currency_symbol() . ' ' . $fomated_price;
    } else if (get_setting('symbol_format') == 4) {
        return $fomated_price . ' ' . currency_symbol();
    }
    return $fomated_price . currency_symbol();
}

function convert_price($price)
{
    if (Session::has('currency_code') && (Session::get('currency_code') != get_system_default_currency()->code)) {
        $price = floatval($price) / floatval(get_system_default_currency()->exchange_rate);
        $price = floatval($price) * floatval(Session::get('currency_exchange_rate'));
    }
    return $price;
}

function currency_symbol()
{
    if (Session::has('currency_symbol')) {
        return Session::get('currency_symbol');
    }
    return get_system_default_currency()->symbol;
}

function get_system_default_currency()
{
    return Cache::remember('system_default_currency', 86400, function () {
        return Currency::findOrFail(get_setting('system_default_currency'));
    });
}


function home_base_price($product, $formatted = true)
{
    $price = $product->unit_price;
    $tax = 0;

    foreach ($product->taxes as $product_tax) {
        if ($product_tax->tax_type == 'percent') {
            $tax += ($price * $product_tax->tax) / 100;
        } elseif ($product_tax->tax_type == 'amount') {
            $tax += $product_tax->tax;
        }
    }
    $price += $tax;
    return $formatted ? format_price(convert_price($price)) : $price;
}

function home_discounted_base_price_by_stock_id($id)
{
    $product_stock = ProductStock::findOrFail($id);
    $product = $product_stock->product;
    $price = $product_stock->price;
    $tax = 0;

    $discount_applicable = false;

    if ($product->discount_start_date == null) {
        $discount_applicable = true;
    } elseif (
        strtotime(date('d-m-Y H:i:s')) >= $product->discount_start_date &&
        strtotime(date('d-m-Y H:i:s')) <= $product->discount_end_date
    ) {
        $discount_applicable = true;
    }

    if ($discount_applicable) {
        if ($product->discount_type == 'percent') {
            $price -= ($price * $product->discount) / 100;
        } elseif ($product->discount_type == 'amount') {
            $price -= $product->discount;
        }
    }

    foreach ($product->taxes as $product_tax) {
        if ($product_tax->tax_type == 'percent') {
            $tax += ($price * $product_tax->tax) / 100;
        } elseif ($product_tax->tax_type == 'amount') {
            $tax += $product_tax->tax;
        }
    }
    $price += $tax;

    return format_price(convert_price($price));
}

function home_discounted_base_price($product, $formatted = true)
{
    $price = $product->unit_price;
    $tax = 0;

    $discount_applicable = false;

    if ($product->discount_start_date == null) {
        $discount_applicable = true;
    } elseif (
        strtotime(date('d-m-Y H:i:s')) >= $product->discount_start_date &&
        strtotime(date('d-m-Y H:i:s')) <= $product->discount_end_date
    ) {
        $discount_applicable = true;
    }

    if ($discount_applicable) {
        if ($product->discount_type == 'percent') {
            $price -= ($price * $product->discount) / 100;
        } elseif ($product->discount_type == 'amount') {
            $price -= $product->discount;
        }
    }

    foreach ($product->taxes as $product_tax) {
        if ($product_tax->tax_type == 'percent') {
            $tax += ($price * $product_tax->tax) / 100;
        } elseif ($product_tax->tax_type == 'amount') {
            $tax += $product_tax->tax;
        }
    }
    $price += $tax;

    return $formatted ? format_price(convert_price($price)) : $price;
}

function isUnique($email)
{
    $user = \App\Models\User::where('email', $email)->first();

    if ($user == null) {
        return '1'; // $user = null means we did not get any match with the email provided by the user inside the database
    } else {
        return '0';
    }
}



function renderStarRating($rating, $maxRating = 5)
{
        $fullStar = "<i class = 'las la-star active'></i>";
        $halfStar = "<i class = 'las la-star half'></i>";
        $emptyStar = "<i class = 'las la-star'></i>";
        $rating = $rating <= $maxRating ? $rating : $maxRating;

        $fullStarCount = (int)$rating;
        $halfStarCount = ceil($rating) - $fullStarCount;
        $emptyStarCount = $maxRating - $fullStarCount - $halfStarCount;

        $html = str_repeat($fullStar, $fullStarCount);
        $html .= str_repeat($halfStar, $halfStarCount);
        $html .= str_repeat($emptyStar, $emptyStarCount);
        echo $html;
}

function cart_product_price($cart_product, $product, $formatted = true, $tax = true)
{
    if ($product->auction_product == 0) {
        $str = '';
        if ($cart_product['variation'] != null) {
            $str = $cart_product['variation'];
        }
        $price = 0;
        $product_stock = $product->stocks->where('variant', $str)->first();
        if ($product_stock) {
            $price = $product_stock->price;
        }


        //discount calculation
        $discount_applicable = false;

        if ($product->discount_start_date == null) {
            $discount_applicable = true;
        } elseif (
            strtotime(date('d-m-Y H:i:s')) >= $product->discount_start_date &&
            strtotime(date('d-m-Y H:i:s')) <= $product->discount_end_date
        ) {
            $discount_applicable = true;
        }

        if ($discount_applicable) {
            if ($product->discount_type == 'percent') {
                $price -= ($price * $product->discount) / 100;
            } elseif ($product->discount_type == 'amount') {
                $price -= $product->discount;
            }
        }
    } else {
        $price = $product->bids->max('amount');
    }

    //calculation of taxes
    if ($tax) {
        $taxAmount = 0;
        foreach ($product->taxes as $product_tax) {
            if ($product_tax->tax_type == 'percent') {
                $taxAmount += ($price * $product_tax->tax) / 100;
            } elseif ($product_tax->tax_type == 'amount') {
                $taxAmount += $product_tax->tax;
            }
        }
        $price += $taxAmount;
    }

    if ($formatted) {
        return format_price(convert_price($price));
    } else {
        return $price;
    }
}

function cart_product_tax($cart_product, $product, $formatted = true)
{
    $str = '';
    if ($cart_product['variation'] != null) {
        $str = $cart_product['variation'];
    }
    $product_stock = $product->stocks->where('variant', $str)->first();
    $price = $product_stock->price;

    //discount calculation
    $discount_applicable = false;

    if ($product->discount_start_date == null) {
        $discount_applicable = true;
    } elseif (
        strtotime(date('d-m-Y H:i:s')) >= $product->discount_start_date &&
        strtotime(date('d-m-Y H:i:s')) <= $product->discount_end_date
    ) {
        $discount_applicable = true;
    }

    if ($discount_applicable) {
        if ($product->discount_type == 'percent') {
            $price -= ($price * $product->discount) / 100;
        } elseif ($product->discount_type == 'amount') {
            $price -= $product->discount;
        }
    }

    //calculation of taxes
    $tax = 0;
    foreach ($product->taxes as $product_tax) {
        if ($product_tax->tax_type == 'percent') {
            $tax += ($price * $product_tax->tax) / 100;
        } elseif ($product_tax->tax_type == 'amount') {
            $tax += $product_tax->tax;
        }
    }

    if ($formatted) {
        return format_price(convert_price($tax));
    } else {
        return $tax;
    }
}

function sendSMS($to, $from, $text, $template_id)
{
    return SendSMSUtility::sendSMS($to, $from, $text, $template_id);
}

function calculateCommissionAffilationClubPoint($order)
{
    (new CommissionController)->calculateCommission($order);

    if (addon_is_activated('affiliate_system')) {
        (new AffiliateController)->processAffiliatePoints($order);
    }

    if (addon_is_activated('club_point')) {
        if ($order->user != null) {
            (new ClubPointController)->processClubPoints($order);
        }
    }

    $order->commission_calculated = 1;
    $order->save();
}


function formatBytes($bytes, $precision = 2)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    // Uncomment one of the following alternatives
    $bytes /= pow(1024, $pow);
    // $bytes /= (1 << (10 * $pow));

    return round($bytes, $precision) . ' ' . $units[$pow];
}


function timezones()
{
    return App\Lib\Timezones::timezonesToArray();
}
    
    
function app_timezone()
{
    return config('app.timezone');
}

function seller_package_validity_check($user_id = null)
{
    $user = $user_id == null ? \App\Models\User::find(Auth::user()->id) : \App\Models\User::find($user_id);
    $shop = $user->shop;
    $package_validation = false;
    if (
        $shop->product_upload_limit > $shop->user->products()->count()
        && $shop->package_invalid_at != null
        && Carbon::now()->diffInDays(Carbon::parse($shop->package_invalid_at), false) >= 0
    ) {
        $package_validation = true;
    }

    return $package_validation;
    // Ture = Seller package is valid and seller has the product upload limit
    // False = Seller package is invalid or seller product upload limit exists.
}

function home_price($product, $formatted = true)
{
    $lowest_price = $product->unit_price;
    $highest_price = $product->unit_price;

    if ($product->variant_product) {
        foreach ($product->stocks as $key => $stock) {
            if ($lowest_price > $stock->price) {
                $lowest_price = $stock->price;
            }
            if ($highest_price < $stock->price) {
                $highest_price = $stock->price;
            }
        }
    }

    foreach ($product->taxes as $product_tax) {
        if ($product_tax->tax_type == 'percent') {
            $lowest_price += ($lowest_price * $product_tax->tax) / 100;
            $highest_price += ($highest_price * $product_tax->tax) / 100;
        } elseif ($product_tax->tax_type == 'amount') {
            $lowest_price += $product_tax->tax;
            $highest_price += $product_tax->tax;
        }
    }

    if ($formatted) {
        if ($lowest_price == $highest_price) {
            return format_price(convert_price($lowest_price));
        } else {
            return format_price(convert_price($lowest_price)) . ' - ' . format_price(convert_price($highest_price));
        }
    } else {
        return $lowest_price . ' - ' . $highest_price;
    }
}

function home_discounted_price($product, $formatted = true)
{
    $lowest_price = $product->unit_price;
    $highest_price = $product->unit_price;

    if ($product->variant_product) {
        foreach ($product->stocks as $key => $stock) {
            if ($lowest_price > $stock->price) {
                $lowest_price = $stock->price;
            }
            if ($highest_price < $stock->price) {
                $highest_price = $stock->price;
            }
        }
    }

    $discount_applicable = false;

    if ($product->discount_start_date == null) {
        $discount_applicable = true;
    } elseif (
        strtotime(date('d-m-Y H:i:s')) >= $product->discount_start_date &&
        strtotime(date('d-m-Y H:i:s')) <= $product->discount_end_date
    ) {
        $discount_applicable = true;
    }

    if ($discount_applicable) {
        if ($product->discount_type == 'percent') {
            $lowest_price -= ($lowest_price * $product->discount) / 100;
            $highest_price -= ($highest_price * $product->discount) / 100;
        } elseif ($product->discount_type == 'amount') {
            $lowest_price -= $product->discount;
            $highest_price -= $product->discount;
        }
    }

    foreach ($product->taxes as $product_tax) {
        if ($product_tax->tax_type == 'percent') {
            $lowest_price += ($lowest_price * $product_tax->tax) / 100;
            $highest_price += ($highest_price * $product_tax->tax) / 100;
        } elseif ($product_tax->tax_type == 'amount') {
            $lowest_price += $product_tax->tax;
            $highest_price += $product_tax->tax;
        }
    }

    if ($formatted) {
        if ($lowest_price == $highest_price) {
            return format_price(convert_price($lowest_price));
        } else {
            return format_price(convert_price($lowest_price)) . ' - ' . format_price(convert_price($highest_price));
        }
    } else {
        return $lowest_price . ' - ' . $highest_price;
    }
}

function home_base_price_by_stock_id($id)
{
    $product_stock = ProductStock::findOrFail($id);
    $price = $product_stock->price;
    $tax = 0;

    foreach ($product_stock->product->taxes as $product_tax) {
        if ($product_tax->tax_type == 'percent') {
            $tax += ($price * $product_tax->tax) / 100;
        } elseif ($product_tax->tax_type == 'amount') {
            $tax += $product_tax->tax;
        }
    }
    $price += $tax;
    return format_price(convert_price($price));
}


function getShippingCost($carts, $index, $carrier = '')
{
    $shipping_type = get_setting('shipping_type');
    $admin_products = array();
    $seller_products = array();
    $admin_product_total_weight = 0;
    $admin_product_total_price = 0;
    $seller_product_total_weight = array();
    $seller_product_total_price = array();

    $cartItem = $carts[$index];
    $product = Product::find($cartItem['product_id']);

    if ($product->digital == 1) {
        return 0;
    }

    foreach ($carts as $key => $cart_item) {
        $item_product = Product::find($cart_item['product_id']);
        if ($item_product->added_by == 'admin') {
            array_push($admin_products, $cart_item['product_id']);

            // For carrier wise shipping
            if ($shipping_type == 'carrier_wise_shipping') {
                $admin_product_total_weight += ($item_product->weight * $cart_item['quantity']);
                $admin_product_total_price += (cart_product_price($cart_item, $item_product, false, false) * $cart_item['quantity']);
            }
        } else {
            $product_ids = array();
            $weight = 0;
            $price = 0;
            if (isset($seller_products[$item_product->user_id])) {
                $product_ids = $seller_products[$item_product->user_id];

                // For carrier wise shipping
                if ($shipping_type == 'carrier_wise_shipping') {
                    $weight += $seller_product_total_weight[$item_product->user_id];
                    $price += $seller_product_total_price[$item_product->user_id];
                }
            }

            array_push($product_ids, $cart_item['product_id']);
            $seller_products[$item_product->user_id] = $product_ids;

            // For carrier wise shipping
            if ($shipping_type == 'carrier_wise_shipping') {
                $weight += ($item_product->weight * $cart_item['quantity']);
                $seller_product_total_weight[$item_product->user_id] = $weight;

                $price += (cart_product_price($cart_item, $item_product, false, false) * $cart_item['quantity']);
                $seller_product_total_price[$item_product->user_id] = $price;
            }
        }
    }

    if ($shipping_type == 'flat_rate') {
        return get_setting('flat_rate_shipping_cost') / count($carts);
    } elseif ($shipping_type == 'seller_wise_shipping') {
        if ($product->added_by == 'admin') {
            return get_setting('shipping_cost_admin') / count($admin_products);
        } else {
            return Shop::where('user_id', $product->user_id)->first()->shipping_cost / count($seller_products[$product->user_id]);
        }
    } elseif ($shipping_type == 'area_wise_shipping') {
        $shipping_info = Address::where('id', $carts[0]['address_id'])->first();
        $city = City::where('id', $shipping_info->city_id)->first();
        if ($city != null) {
            if ($product->added_by == 'admin') {
                return $city->cost / count($admin_products);
            } else {
                return $city->cost / count($seller_products[$product->user_id]);
            }
        }
        return 0;
    } elseif ($shipping_type == 'carrier_wise_shipping') { // carrier wise shipping
        $user_zone = Address::where('id', $carts[0]['address_id'])->first()->country->zone_id;
        if ($carrier == null || $user_zone == 0) {
            return 0;
        }

        $carrier = Carrier::find($carrier);
        if ($carrier->carrier_ranges->first()) {
            $carrier_billing_type   = $carrier->carrier_ranges->first()->billing_type;
            if ($product->added_by == 'admin') {
                $itemsWeightOrPrice = $carrier_billing_type == 'weight_based' ? $admin_product_total_weight : $admin_product_total_price;
            } else {
                $itemsWeightOrPrice = $carrier_billing_type == 'weight_based' ? $seller_product_total_weight[$product->user_id] : $seller_product_total_price[$product->user_id];
            }
        }

        foreach ($carrier->carrier_ranges as $carrier_range) {
            if ($itemsWeightOrPrice >= $carrier_range->delimiter1 && $itemsWeightOrPrice < $carrier_range->delimiter2) {
                $carrier_price = $carrier_range->carrier_range_prices->where('zone_id', $user_zone)->first()->price;
                return $product->added_by == 'admin' ? ($carrier_price / count($admin_products)) : ($carrier_price / count($seller_products[$product->user_id]));
            }
        }
        return 0;
    } else {
        if ($product->is_quantity_multiplied && ($shipping_type == 'product_wise_shipping')) {
            return  $product->shipping_cost * $cartItem['quantity'];
        }
        return $product->shipping_cost;
    }
}

function seller_purchase_payment_done($user_id, $seller_package_id, $amount, $payment_method, $payment_details)
{
    $seller = Shop::where('user_id', $user_id)->first();
    $seller->seller_package_id = $seller_package_id;
    $seller_package = SellerPackage::findOrFail($seller_package_id);
    $seller->product_upload_limit = $seller_package->product_upload_limit;
    $seller->package_invalid_at = date('Y-m-d', strtotime($seller->package_invalid_at . ' +' . $seller_package->duration . 'days'));
    $seller->save();

    $seller_package = new SellerPackagePayment();
    $seller_package->user_id = $user_id;
    $seller_package->seller_package_id = $seller_package_id;
    $seller_package->payment_method = $payment_method;
    $seller_package->payment_details = $payment_details;
    $seller_package->approval = 1;
    $seller_package->offline_payment = 2;
    $seller_package->save();
}