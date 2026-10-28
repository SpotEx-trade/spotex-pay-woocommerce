=== SpotEx Pay for WooCommerce ===
Contributors: spotex
Tags: crypto, bitcoin, payments, woocommerce, peercoin
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take crypto at checkout. Buyers pay from any wallet or a SpotEx balance; you are paid in your SpotEx merchant wallet.

== Description ==

SpotEx Pay adds a crypto payment method to your WooCommerce checkout, classic or block.

* Buyers pay on SpotEx's own page, from any crypto wallet or their SpotEx balance, then come back to your thank-you page.
* Your prices stay in your shop's currency: USD, EUR, GBP, JPY, IDR and 30 more. Buyers pay its worth in their coin at the rate when they check out.
* Orders are marked paid by a signed webhook from SpotEx, never by the buyer coming back. If the webhook is late, the plugin asks SpotEx itself when they do.
* 0.5% per payment. No setup or monthly fee.

You need a SpotEx merchant account: apply at https://spotex.trade/merchants. Your merchant website must be this shop's address, as buyers are sent back to it.

== Installation ==

1. Upload the plugin and activate it.
2. In SpotEx Merchant Center → Developers, make a secret key (sk_live_…), and set the webhook address to https://your-shop/wp-json/spotex-pay/v1/webhook. Copy its signing secret (whsec_…).
3. In WooCommerce → Settings → Payments → SpotEx Pay, paste both, and enable it.

To try it first, use a test key (sk_test_…): test payments put an order on hold with a note, and never mark it paid.

== Frequently Asked Questions ==

= What happens if a buyer pays too little, or twice? =

SpotEx holds a short payment until the rest arrives, or until you accept it in the Merchant Center; the order gets a note. A second payment for the same order is held too, with a note, for you to refund or keep.

= Can I refund from WooCommerce? =

Not yet: refund from the SpotEx Merchant Center. The order gets a note when you do.

== Changelog ==

= 0.1.0 =
* First release.
