# SpotEx Pay for WooCommerce

[![tests](https://github.com/SpotEx-trade/spotex-pay-woocommerce/actions/workflows/tests.yml/badge.svg)](https://github.com/SpotEx-trade/spotex-pay-woocommerce/actions/workflows/tests.yml)

Take crypto at your WooCommerce checkout with [SpotEx Pay](https://spotex.trade/merchants). Buyers pay from any wallet or their SpotEx balance; the money lands in your SpotEx merchant wallet, less 0.5%.

- Works with the classic and the block checkout, and with high-performance order storage.
- Prices stay in your shop's currency: any of [these 37](https://spotex.trade/docs/merchant#currencies).
- Orders are marked paid by SpotEx's signed webhook, never by the buyer coming back; if the webhook is late, the plugin asks SpotEx itself.
- A test key (`sk_test_…`) only ever puts an order on hold: test payments can't give goods away.
- A part payment SpotEx passes on without you accepting it (`checkout.released`) gets a note on the order, never marks it paid.

## Install

1. Download this repository as a zip (Code → Download ZIP), and in WordPress go to Plugins → Add New → Upload Plugin.
2. In [SpotEx Merchant Center](https://spotex.trade/merchant) → Developers, make a secret key and set the webhook address to `https://your-shop/wp-json/spotex-pay/v1/webhook`. Copy the signing secret.
3. In WooCommerce → Settings → Payments → SpotEx Pay, paste the key and the secret, and enable it.

Your SpotEx merchant website must be this shop's address: buyers are sent back to it after paying.

Use one shop per SpotEx merchant account. SpotEx sends an account's webhooks to one address, so a second shop on the same account would hear of its payments only when buyers come back.

## How it works

At checkout the plugin makes a [checkout session](https://spotex.trade/docs/merchant#sessions) for the order's total and sends the buyer to it. One order and total is one session, so a buyer going back and paying again lands on the same one. SpotEx's `checkout.paid` webhook — checked against its signature, the order's session, total, currency and mode — marks the order paid.

## Development

```sh
php tests/run.php
```

The tests cover the plugin's own rules without WordPress, and run on PHP 7.4 to 8.5 on every push.

## License

GPL-2.0-or-later, as WordPress plugins are.
