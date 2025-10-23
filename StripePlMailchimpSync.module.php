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
	  'version'     => '0.2.0',
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
	$this->addHookAfter('Pages::saved', $this, 'afterPurchaseAdded');
	
	$this->addHookAfter('Modules::saveConfig', function($e){
		$m    = $e->arguments(0);
		$data = (array) $e->arguments(1);
		$name = $m instanceof \ProcessWire\Module ? $m->className() : (string) $m;
		if ($name !== 'StripePlMailchimpSync') return;
		$this->triggerResyncFromConfig($data);
	  });
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
	
	$fs = $this->modules->get('InputfieldFieldset');
	$fs->label = 'Mailchimp Resync';
	
	$f = $this->modules->get('InputfieldEmail');
	$f->attr('name', 'resyncEmail');
	$f->label = 'Filter by buyer email (optional)';
	$f->attr('placeholder', 'name@example.com');
	$f->value = $data['resyncEmail'] ?? '';
	$f->columnWidth = 33;
	$fs->add($f);
	
	$f = $this->modules->get('InputfieldDatetime');
	$f->attr('name', 'resyncFrom');
	$f->label = 'From purchase date (optional)';
	$f->value = (int) $this->get('resyncFrom') ?: null;
	$f->datepicker = 1;
	$f->timepicker = 0;
	$f->attr('placeholder', 'YYYY-MM-DD');
	$f->columnWidth = 33;
	$fs->add($f);
	
	$f = $this->modules->get('InputfieldDatetime');
	$f->attr('name', 'resyncTo');
	$f->label = 'To purchase date (optional)';
	$f->datepicker = 1;
	$f->timepicker = 0;
	$f->attr('placeholder', 'YYYY-MM-DD');
	$f->value = (int) $this->get('resyncTo') ?: null;
	$f->columnWidth = 33;
	$fs->add($f);
	
	$f = $this->modules->get('InputfieldCheckbox');
	$f->columnWidth = 50;
	$f->attr('name', 'resyncDryRun');
	$f->label = 'Dry-run (log only, no Mailchimp calls)';
	$fs->add($f);
	
		$f = $this->modules->get('InputfieldCheckbox');
	$f->attr('name', 'resyncUnsyncedOnly');
	$f->label = 'Only unsynced items';
	$f->checked = !empty($data['resyncUnsyncedOnly']);
	$f->columnWidth = 50;
	$fs->add($f);
	
	$f = $this->modules->get('InputfieldCheckbox');
	$f->attr('name', 'resyncRun');
	$f->label = 'Run resync now';
	$fs->add($f);
	
	$report = $this->wire('session')->get('spl_mailchimp_resync_report');
	if ($report) {
		$out = $this->modules->get('InputfieldMarkup');
		$out->name  = 'resyncReport';
		$out->label = 'Resync report';
		$out->value = '<pre style="white-space:pre-wrap;max-height:400px;overflow:auto;margin:0">' 
				. htmlspecialchars((string)$report, ENT_QUOTES, 'UTF-8')
				. '</pre>';
		$fs->add($out);
		$this->wire('session')->remove('spl_mailchimp_resync_report');
	}
	$inputfields->add($fs);
	  
	return $inputfields;
  }
  
    /**
	 * Trigger a manual Mailchimp resync based on module configuration.
	 *
	 * Called automatically after the module config is saved when the "Run resync now"
	 * checkbox is active. Filters purchase repeater items by:
	 * - date range (`resyncFrom` / `resyncTo`)
	 * - optional user email (`resyncEmail`)
	 * - and optional "unsynced only" flag (`resyncUnsyncedOnly`)
	 *
	 * Supports a dry-run mode that logs intended actions without performing API calls.
	 * Generates a detailed report and stores it in the session to display in the config UI.
	 *
	 * @param array $data Saved configuration values from Modules::saveConfig
	 * @return void
	 */
	protected function triggerResyncFromConfig(array $data): void {
	  if (empty($data['resyncRun'])) return;
  
	  $unsyncedOnly = filter_var($data['resyncUnsyncedOnly'] ?? false, FILTER_VALIDATE_BOOLEAN);
	  $dryRun       = filter_var($data['resyncDryRun']       ?? false, FILTER_VALIDATE_BOOLEAN);
  
	  $san         = $this->wire('sanitizer');
	  $filterEmail = $san->email((string)($data['resyncEmail'] ?? '')); // optional
  
	  $from = (int) ($data['resyncFrom'] ?? 0) ?: null;
	  $to   = (int) ($data['resyncTo']   ?? 0) ?: null;
	  if ($to !== null) $to += 86399; // inclusive end-of-day
  
	  $pages = $this->wire('pages');
  
	  // Build base item set
	  $items = null;
	  $selector = "template=repeater_spl_purchases, limit=1000";
	  if ($from) $selector .= ", purchase_date>={$from}";
	  if ($to)   $selector .= ", purchase_date<={$to}";
  
	  if ($filterEmail !== '') {
		  // Narrow to purchases of the one user (cannot use getForPage() in selectors)
		  $user = $this->wire('users')->get('email=' . $san->email($filterEmail));
		  if ($user && $user->id && $user->hasField('spl_purchases')) {
			  $items = $user->spl_purchases; // PageArray
			  // Apply date filter on the user's items if needed
			  $inner = [];
			  if ($from) $inner[] = "purchase_date>={$from}";
			  if ($to)   $inner[] = "purchase_date<={$to}";
			  if ($inner) $items = $items->find(implode(', ', $inner));
		  } else {
			  $items = new \ProcessWire\PageArray(); // no matches
		  }
	  } else {
		  // Global search
		  $items = $pages->find($selector);
	  }
  
	  // ===== Detailed report buffer (header) =====
	  $r = [];
	  $r[] = '== StripePaymentLinks Mailchimp Resync ==';
	  $r[] = 'Mode: ' . ($dryRun ? 'DRY RUN (no writes)' : 'WRITE');
	  $r[] = 'Only unsynced: ' . ($unsyncedOnly ? 'yes' : 'no');
	  $r[] = 'Email: ' . ($filterEmail !== '' ? $filterEmail : '—');
	  $r[] = 'From: ' . ($from ? date('Y-m-d', $from) : '—');
	  $r[] = 'To:   ' . ($to   ? date('Y-m-d', $to)   : '—');
	  // Show the global selector only when not using the user-specific path
	  if ($filterEmail === '') $r[] = 'Selector: ' . $selector;
	  $r[] = '';
  
	  $synced = 0; $skipped = 0; $errors = 0; $wouldSync = 0;
  
	  foreach ($items as $it) {
		  /** @var \ProcessWire\Page $it */
		  $already = (bool) $it->meta('mc_synced_at');
  
		  $whenTs = (int)($it->get('purchase_date') ?: $it->created);
		  $when   = $whenTs ? date('Y-m-d H:i', $whenTs) : '-';
  
		  // Resolve owner and email (works both in global and user-filter paths)
		  $owner = method_exists($it, 'getForPage') ? $it->getForPage() : null;
		  $ownerEmail = ($owner instanceof \ProcessWire\User) ? (string)$owner->email : '';
  
		  // If an email filter is set, enforce it here as well (belt & suspenders)
		  if ($filterEmail !== '' && strtolower($ownerEmail) !== strtolower($filterEmail)) continue;
  
		  // Build tag list for reporting
		  $tagsArr = $this->purchaseTagsFromItem($it);
		  $tags    = $tagsArr ? implode(', ', $tagsArr) : '';
  
		  if ($dryRun) {
			  if ($unsyncedOnly && $already) {
				  $skipped++;
				  $r[] = sprintf('%s  #%d  %s  [SKIP already synced]', $when, (int)$it->id, $ownerEmail ?: '(no email)');
			  } else {
				  $wouldSync++;
				  $r[] = sprintf('%s  #%d  %s  ⇒ DRY would sync  | tags: %s', $when, (int)$it->id, $ownerEmail ?: '(no email)', $tags);
			  }
			  continue;
		  }
  
		  if ($unsyncedOnly && $already) {
			  $skipped++;
			  $r[] = sprintf('%s  #%d  %s  [SKIP already synced]', $when, (int)$it->id, $ownerEmail ?: '(no email)');
			  continue;
		  }
  
		  try {
			  $this->syncPurchaseToMailchimp($it);
			  $synced++;
			  $r[] = sprintf('%s  #%d  %s  ⇒ SYNCED         | tags: %s', $when, (int)$it->id, $ownerEmail ?: '(no email)', $tags);
		  } catch (\Throwable $e) {
			  $errors++;
			  $r[] = sprintf('%s  #%d  %s  [ERROR] %s', $when, (int)$it->id, $ownerEmail ?: '(no email)', $e->getMessage());
		  }
	  }
  
	  // Footer + totals
	  $fmt = fn($ts) => $ts ? date('Y-m-d H:i:s', $ts) . ' (' . $ts . ')' : '-';
	  $r[] = '';
	  $r[] = 'Totals:'
		  . ' synced=' . $synced
		  . ', skipped=' . $skipped
		  . ', errors=' . $errors
		  . ($dryRun ? (', wouldSync=' . $wouldSync) : '');
  
	  $this->wire('session')->set('spl_mailchimp_resync_report', implode("\n", $r));
  
	  // Reset the run-flag so it doesn't re-trigger
	  $data['resyncRun'] = false;
	  $this->modules->saveConfig('StripePlMailchimpSync', $data);
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
    if($page->meta('mc_synced_at')) return;
	
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
	if ($item->meta('mc_synced_at')) return;
		
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
	  
	 // Mark as synced (meta) and persist WITHOUT re-saving the page
	  $item->meta('mc_synced_at', time());
	  $item->save('meta');

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
	if ($apiKey === '' || $audId === '' || strpos($apiKey, '-') === false) {
	  $this->wire('log')->save('spl_mailchimp', 'Mailchimp config invalid or incomplete.');
	  return;
	}
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
	  CURLOPT_TIMEOUT       => 20,
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