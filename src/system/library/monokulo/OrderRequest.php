<?php
namespace Monokulo\OpenCart;

/**
 * The body of `POST /pay/{pk}/orders` for one OpenCart order.
 *
 * The amount is the order total in the currency the customer saw, and
 * Monokulo converts it to XMR at its own rate: the shop never converts.
 * The OpenCart order id goes along as the merchant's reference, kept off the
 * checkout (it means nothing to the customer) but shown in the dashboard and
 * in webhooks. The idempotency key makes a retried confirm get the same
 * Monokulo order, and a changed total or currency a new one.
 */
final class OrderRequest {
	public const MAX_DESCRIPTION_CHARS = 140;

	/**
	 * @param list<string> $itemNames
	 *
	 * @return array<string, mixed>
	 */
	public static function body(int $storeId, int $orderId, float $total, string $currency, int $decimals, array $itemNames, string $descriptionPrefix, string $moreFormat): array {
		$amount = self::amount($total, $currency, $decimals);
		$currency = strtoupper($currency);

		return [
			'amount'                 => $amount,
			'currency'               => $currency,
			'merchant_order_id'      => (string)$orderId,
			'show_merchant_order_id' => false,
			'description'            => self::description($descriptionPrefix, $itemNames, $moreFormat),
			'idempotency_key'        => sprintf('oc:%d:%d:%s:%s', $storeId, $orderId, $amount, $currency),
		];
	}

	/**
	 * The total as a decimal string, with the currency's decimal places (at
	 * least 2; XMR up to 12).
	 */
	public static function amount(float $total, string $currency, int $decimals): string {
		$max = strtoupper($currency) === 'XMR' ? 12 : 8;
		$decimals = max(2, min($max, $decimals));

		return number_format($total, $decimals, '.', '');
	}

	/**
	 * "Order #1042: Blue mug + 2 more", on one line, at most 140 characters.
	 * `prefix` is the translated "Order #1042", `moreFormat` the translated
	 * " + %d more".
	 *
	 * @param list<string> $itemNames
	 */
	public static function description(string $prefix, array $itemNames, string $moreFormat): string {
		$description = $prefix;
		if ($itemNames) {
			$description .= ': ' . $itemNames[0];
			if (count($itemNames) > 1) {
				$description .= sprintf($moreFormat, count($itemNames) - 1);
			}
		}
		$description = trim((string)preg_replace('/[\p{Cc}\s]+/u', ' ', $description));
		if (mb_strlen($description, 'UTF-8') > self::MAX_DESCRIPTION_CHARS) {
			$description = rtrim(mb_substr($description, 0, self::MAX_DESCRIPTION_CHARS - 1, 'UTF-8')) . '…';
		}

		return $description;
	}
}
