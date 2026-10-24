<?php
namespace Monokulo\OpenCart\Tests\Unit;

use Monokulo\OpenCart\ApiError;
use Monokulo\OpenCart\TestWebhookReport;
use Monokulo\OpenCart\Tests\Support\FakeMonokulo;
use Monokulo\OpenCart\TransportError;
use PHPUnit\Framework\TestCase;

/**
 * What the Test connection button tells the admin, in the admin's own
 * language file's words, from Monokulo's recorded answers (FakeMonokulo):
 * delivered, not delivered, and each problem.
 */
final class TestWebhookReportTest extends TestCase {
	private const DATE = 'd/m/Y H:i:s';

	/** @var array<string, string> */
	private static array $language;

	public static function setUpBeforeClass(): void {
		$_ = [];
		require __DIR__ . '/../../src/admin/language/en-gb/payment/monokulo.php';
		self::$language = $_;
	}

	private function say(TestWebhookReport $report): string {
		return $report->message(static fn(string $key): string => self::$language[$key] ?? throw new \LogicException('no ' . $key), self::DATE);
	}

	/** @return array{event_id: string, delivered: bool, status: ?int, duration_ms: int, error: ?string, next_attempt_at: ?int} */
	private static function outcome(bool $delivered): array {
		$answer = FakeMonokulo::testOutcome($delivered);

		return ['event_id' => $answer['event_id'], 'delivered' => $answer['delivered'], 'status' => $answer['status'] ?? null, 'duration_ms' => $answer['duration_ms'], 'error' => $answer['error'] ?? null, 'next_attempt_at' => $answer['next_attempt_at'] ?? null];
	}

	private static function problem(string $name): ApiError {
		$problem = FakeMonokulo::recorded('POST /api/v1/webhook-tests', $name);

		return new ApiError($problem['status'], $problem['detail'], $name, $problem['retry_after'] ?? null);
	}

	public function test_delivered_to_this_shops_receiver_says_the_status_and_time(): void {
		$report = TestWebhookReport::fromOutcome(self::outcome(true), 'evt_0000000000000002');

		$this->assertTrue($report->ok);
		$this->assertSame('Test webhook evt_0000000000000002 delivered: this shop checked its signature and answered HTTP 200 in 12 ms. No order changed.', $this->say($report));
	}

	/** Answered 2xx, but this shop's receiver never took that event: the webhook points somewhere else. */
	public function test_delivered_elsewhere_is_a_warning(): void {
		$report = TestWebhookReport::fromOutcome(self::outcome(true), 'evt_older');

		$this->assertFalse($report->ok);
		$this->assertStringContainsString('was answered with HTTP 200 in 12 ms, but not by this shop', $this->say($report));
	}

	public function test_not_delivered_says_why_and_when_it_is_tried_again(): void {
		$report = TestWebhookReport::fromOutcome(self::outcome(false), '');

		$this->assertFalse($report->ok);
		$this->assertSame('Monokulo\'s test webhook evt_0000000000000002 was not delivered: answered 500. Monokulo tries it again at ' . date(self::DATE, FakeMonokulo::NEXT_ATTEMPT) . '.', $this->say($report));
	}

	public function test_not_delivered_and_not_tried_again(): void {
		$outcome = ['next_attempt_at' => null, 'error' => null, 'status' => null] + self::outcome(false);

		$this->assertSame('Monokulo\'s test webhook evt_0000000000000002 was not delivered: no answer.', $this->say(TestWebhookReport::fromOutcome($outcome, '')));
	}

	public function test_rate_limited_says_how_long_to_wait(): void {
		$this->assertSame('Monokulo sends at most 6 test webhooks a minute for a store. Try again in 120 seconds.', $this->say(TestWebhookReport::fromError(self::problem('rate-limited'))));
	}

	public function test_not_found_says_to_connect_again(): void {
		$report = TestWebhookReport::fromError(self::problem('not-found'));

		$this->assertFalse($report->ok);
		$this->assertStringStartsWith('Monokulo has no webhook for this store to test. Connect the store again', $this->say($report));
	}

	public function test_a_refused_key_says_to_connect_again(): void {
		foreach (['unauthorized', 'forbidden'] as $name) {
			$this->assertSame('Monokulo refused this store\'s key. Connect the store again.', $this->say(TestWebhookReport::fromError(self::problem($name))), $name);
		}
	}

	public function test_anything_else_says_what_monokulo_said(): void {
		$this->assertSame('Monokulo could not send a test webhook: HTTP 500: Something went wrong on this Monokulo. Try again in a moment.', $this->say(TestWebhookReport::fromError(self::problem('internal'))));
	}

	public function test_out_of_reach_says_so(): void {
		$this->assertSame('Your Monokulo could not be reached from this shop\'s server: Connection refused.', $this->say(TestWebhookReport::fromError(new TransportError('Connection refused'))));
	}

	public function test_a_store_not_connected_has_nothing_to_test(): void {
		$this->assertSame('Connect this store before testing its connection.', $this->say(TestWebhookReport::notConnected()));
	}

	/** The admin's alert shows HTML: what Monokulo or the receiver said is shown as text. */
	public function test_what_monokulo_said_is_escaped(): void {
		$outcome = ['error' => '<script>alert(1)</script>', 'next_attempt_at' => null] + self::outcome(false);

		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $this->say(TestWebhookReport::fromOutcome($outcome, '')));
		$this->assertStringNotContainsString('<script>', $this->say(TestWebhookReport::fromError(new ApiError(502, '<b>bad gateway</b>'))));
	}
}
