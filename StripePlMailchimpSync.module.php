<?php namespace ProcessWire;

use ProcessWire\Page;
use ProcessWire\User;
use ProcessWire\WireData;
use ProcessWire\Module;
use ProcessWire\ConfigurableModule;

/**
 * Stripe Payment Links Mailchimp Sync
 *
 * Minimal add-on module for StripePaymentLinks:
 * - Listens for newly created purchase repeater items (repeater_spl_purchases)
 * - Extracts buyer (name/email) and product titles (as tags)
 * - Syncs them to Mailchimp (create or just update depending on config)
 *
 * Configuration (module settings):
 * - mailchimpApiKey (string)       : Mailchimp API key, e.g. "xxx-us13"
 * - mailchimpAudienceId (string)   : Audience/List ID
 * - createIfMissing (checkbox)     : If enabled, create subscriber if not present; otherwise only update existing
 *
 * Notes:
 * - We hook "Pages::added" to run exactly once when the purchase item is created
 *   to avoid duplicate syncs caused by subsequent saves.
 */
class StripePlMailchimpSync extends WireData implements Module, ConfigurableModule {

  /**
   * Module metadata for ProcessWire
   */
  public static function getModuleInfo(): array {
	return [
	  'title'       => 'StripePaymentLinks Mailchimp Sync',
	  'version'     => '0.1.0',
	  'summary'     => 'Sync purchases from StripePaymentLinks to Mailchimp',
	  'author'      => 'frameless Media',
	  'autoload'    => true,
	  'singular'    => true,
	  'icon'        => 'credit-card',
	  'requires'    => ['StripePaymentLinks'],
	];
  }

  /**
   * Module initialization
   * - Register our hook that fires once when a purchase item is created
   */
  public function init(): void {
	// Only fire once when a new purchase repeater item is created
	$this->addHookAfter('Pages::added', $this, 'afterPurchaseAdded');
  }

  /**
   * Render module config inputfields in the admin
   *
   * @param array $data Saved config data
   * @return \ProcessWire\InputfieldWrapper
   */
  public function getModuleConfigInputfields(array $data) {
	$inputfields = new \ProcessWire\InputfieldWrapper();

	$f = $this->modules->get('InputfieldText');
	$f->attr('name', 'mailchimpApiKey');
	$f->label = 'Mailchimp API Key';
	$f->value = $data['mailchimpApiKey'] ?? '';
	$inputfields->add($f);

	$f = $this->modules->get('InputfieldText');
	$f->attr('name', 'mailchimpAudienceId');
	$f->label = 'Mailchimp Audience ID';
	$f->columnWidth = 50;
	$f->value = $data['mailchimpAudienceId'] ?? '';
	$inputfields->add($f);

	$f = $this->modules->get('InputfieldCheckbox');
	$f->attr('name', 'createIfMissing');
	$f->label = 'Create new subscriber if not existing';
	$f->notes = 'Be sure to inform your customers and provide an opt-out.';
	$f->columnWidth = 50;
	$f->checked = !empty($data['createIfMissing']);
	$inputfields->add($f);

	return $inputfields;
  }

  /**
   * Hook callback: runs after a page was added.
   * We only act on repeater items with template 'repeater_spl_purchases'.
   *
   * @param \ProcessWire\HookEvent $event
   * @return void
   */
  public function afterPurchaseAdded($event): void {
	$page = $event->arguments(0);
	if(!$page instanceof Page) return;
	if((string)$page->template !== 'repeater_spl_purchases') return;

	$this->syncPurchaseToMailchimp($page);
  }

  /**
   * Core sync routine:
   * - Determine owner user of the purchase item
   * - Build tags from Stripe session (fallback to purchase_lines)
   * - Split buyer name if needed
   * - Call Mailchimp
   *
   * @param Page $item Repeater item page (purchase)
   * @return void
   */
  protected function syncPurchaseToMailchimp(Page $item): void {
	try {
	  // Get owning user (repeater item → getForPage())
	  if(!method_exists($item, 'getForPage')) return;
	  $user = $item->getForPage();
	  if(!$user || !$user->id || $user->template != 'user') return;

	  $email = (string) $user->email;
	  if($email === '') return;

	  $full  = trim((string) $user->title);
	  if ($full === '' && strpos($email, '@') !== false) $full = substr($email, 0, strpos($email, '@'));
	  $parts = $this->splitFullNameSmart($full);
	  $first = (string) $parts['first'];
	  $last  = (string) $parts['last'];

	  // Collect product names as tags
	  $tags = $this->purchaseTagsFromItem($item);
	  if(!$tags) return;

	  $this->subscribeToMailchimp($email, $tags, $first, $last);

	  // Logging (human-readable)
	  $this->wire('log')->save(
		'spl_mailchimp',
		'sync success: Synched ' . $email . ' | ' . implode(', ', $tags) . ' | ' . $first . ' | ' . $last
	  );

	} catch(\Throwable $e) {
	  $this->wire('log')->save('spl_mailchimp', 'sync error: ' . $e->getMessage());
	}
  }

  /**
   * Extract product titles from Stripe session meta (preferred),
   * falling back to parsing purchase_lines (format: "PID • QTY • TITLE • TOTAL").
   *
   * @param Page $item Purchase repeater item
   * @return array Unique, non-empty product titles
   */
  protected function purchaseTagsFromItem(Page $item): array {
	$tags = [];

	// Preferred: product names from expanded Stripe session (line_items)
	try {
	  $sess = $item->meta('stripe_session');
	  if(is_object($sess) && method_exists($sess,'toArray')) $sess = $sess->toArray();
	  if(is_array($sess)) {
		foreach(($sess['line_items']['data'] ?? []) as $li) {
		  $name = $li['price']['product']['name']
			   ?? $li['description']
			   ?? $li['price']['nickname']
			   ?? '';
		  $name = trim((string)$name);
		  if($name !== '') $tags[] = $name;
		}
	  }
	} catch(\Throwable $e) {
	  // Swallow and continue with fallback
	}

	// Fallback: parse purchase_lines
	if(!$tags && !empty($item->purchase_lines)) {
	  foreach(preg_split('~\r\n|\r|\n~', trim((string)$item->purchase_lines)) ?: [] as $line) {
		$parts = array_map('trim', explode('•', $line));
		$title = $parts[2] ?? '';
		if($title !== '') $tags[] = $title;
	  }
	}

	// Deduplicate + drop empties
	return array_values(array_unique(array_filter($tags)));
  }

  /**
   * Minimal Mailchimp client:
   * - Upsert member (PUT)
   * - Apply tags (POST)
   * Honors module config for API key, audience, and "create if missing" policy.
   *
   * @param string $email
   * @param array  $tags
   * @param string $first
   * @param string $last
   * @return void
   */
  protected function subscribeToMailchimp(string $email, array $tags, string $first, string $last): void {
	$apiKey = (string) $this->mailchimpApiKey;
	$audId  = (string) $this->mailchimpAudienceId;
	if($apiKey === '' || $audId === '' || $email === '') return;

	// Derive datacenter ("usX") from key suffix
	$dc   = substr(strrchr($apiKey, '-'), 1);
	$hash = md5(strtolower($email));

	// Upsert member
	$url  = "https://{$dc}.api.mailchimp.com/3.0/lists/{$audId}/members/{$hash}";
	$data = [
	  'email_address' => $email,
	  'status_if_new' => $this->createIfMissing ? 'subscribed' : 'pending',
	  'merge_fields'  => ['FNAME' => $first, 'LNAME' => $last],
	];
	$this->mcRequest($url, 'PUT', $data, $apiKey);

	// Apply tags (if any)
	if($tags) {
	  $urlTags = "https://{$dc}.api.mailchimp.com/3.0/lists/{$audId}/members/{$hash}/tags";
	  $payload = ['tags' => array_map(fn($t) => ['name' => $t, 'status' => 'active'], $tags)];
	  $this->mcRequest($urlTags, 'POST', $payload, $apiKey);
	}
  }

  /**
   * Tiny cURL helper for Mailchimp requests.
   * Logs transport errors to /site/assets/logs/spl_mailchimp.txt.
   *
   * @param string $url
   * @param string $method
   * @param array  $data
   * @param string $apiKey
   * @return string|null Raw response (ignored here)
   */
  protected function mcRequest(string $url, string $method, array $data, string $apiKey) {
	$ch = curl_init($url);
	curl_setopt_array($ch, [
	  CURLOPT_USERPWD       => 'user:' . $apiKey,
	  CURLOPT_RETURNTRANSFER=> true,
	  CURLOPT_TIMEOUT       => 10,
	  CURLOPT_CUSTOMREQUEST => $method,
	  CURLOPT_HTTPHEADER    => ['Content-Type: application/json'],
	  CURLOPT_POSTFIELDS    => json_encode($data),
	]);
	$res = curl_exec($ch);
	if(curl_errno($ch)) {
	  $this->wire('log')->save('spl_mailchimp', 'cURL error: ' . curl_error($ch));
	}
	curl_close($ch);
	return $res;
  }

  /**
   * Simple name splitter (best effort, language-agnostic)
   * - If only one part: first = part, last = ''
   * - Otherwise: last word becomes last name
   *
   * @param string $full Full display name
   * @return array{first:string,last:string}
   */
  protected function splitFullNameSmart(string $full): array {
	$full = trim(preg_replace('~\s+~u', ' ', $full));
	if($full === '') return ['first' => '', 'last' => ''];
	$parts = preg_split('~\s+~u', $full) ?: [];
	if(count($parts) === 1) return ['first' => $parts[0], 'last' => ''];
	$last  = array_pop($parts);
	return ['first' => implode(' ', $parts), 'last' => $last];
  }
}