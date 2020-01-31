<?php
/**
 * @version            1.0.0
 * @package            Joomla
 * @subpackage         Membership Pro
 * @author             Diego Luis Soler
 * @copyright          Copyright (C) 2008 - 2019 Diego Soler
 * @license            GNU/GPL, see LICENSE.php
 */
// no direct access
defined('_JEXEC') or die;

// Log category
const DLS_MP_LOG_CAT = 'dls_mercadopago_plg';

// Default redirect timeout to Mercado Pago
const DEFAULT_REDIRECT_TIMEOUT = 5000;
const DEFAULT_REDIRECT_TEXT = "En unos instantes será redireccionado hacia el sitio de Mercado Pago";
const DEFAULT_REDIRECT_NOJS_TEXT = "Si en 10 segundos no ha sido redireccionado hacia el sitio de Mercado Pago, por favor haga clic en:";
const DEFAULT_REDIRECT_LINK_TEXT = "Ir a MercadoPago";
const DEFAULT_REDIRECT_LINK_TITLE = "Ir a MercadoPago";

use Joomla\CMS\Log\Log;

// SDK de Mercado Pago
require __DIR__ .  '/mercadopago/vendor/autoload.php';

class dls_mercadopago extends MPFPayment
{
	/**
	 * @var Object $backUrls Object containing the mercado pago back urls
	 */
	protected $backUrls = null;
	/**
	 * @var MercadoPago\Payment $payment The payment object obtained from Mercado Pago during the notification process
	 */
	protected $payment = null;

	/**
	 * @var MercadoPago\MerchantOrder $merchantOrder the merchant order obtained from mercado pago during the notification process
	 */
	protected $merchantOrder = null;

	/**
	 * Constructor functions, init some parameter
	 *
	 * @param \Joomla\Registry\Registry $params
	 * @param array                     $config
	 */
	public function __construct($params, $config = array())
	{
		parent::__construct($params, $config);

		// Get the debug log parameter from settings
		$this->debugLog = $params->get('debug_log') === '1';

		$this->ipnLog = $params->get('ipn_log') === '1';

		if($this->debugLog || $this->ipnLog) {
			// Create a custom log for this extension
			$this->createCustomLog();
		}

		// Get the mode: production or testing
		$paymentMode = $params->get('mode');

		// Depending of the mode, set the access token
		if ($paymentMode === '1')
		{
			// Production mode
			$accessToken = $params->get('production_access_token');
		}
		else
		{
			// Testing mode
			$accessToken = $params->get('testing_access_token');
		}

		// Get the timeout for redirection to MercadoPago web site
		$redirectTimeout = intval($params->get('redirect_timeout'));
		if($redirectTimeout <= 0) {
			$redirectTimeout = DEFAULT_REDIRECT_TIMEOUT;
		}
		$this->redirectTimeout = $redirectTimeout;

		// Set the Mercado Pago back urls
		$this->setBackUrls();

		// Check if the token is correct
		if(empty($accessToken)) {
			if($this->debugLog) {
				JLog::add("Mercado Pago access token is empty!", JLog::DEBUG, DLS_MP_LOG_CAT);
			}
			JError::raiseError(10001, 'El access Token de Mercado Pago está vacío' );
		}

		// Add MercadoPago credentials
		MercadoPago\SDK::setAccessToken($accessToken);

		// Log data if it is required
		if($this->debugLog)
		{
			$backUrlsString = print_r($this->backUrls, true);
			JLog::add("+-------------------- Plugin constructor --------------------+", JLog::DEBUG, DLS_MP_LOG_CAT);
			JLog::add("Mode: {$paymentMode}", JLog::DEBUG, DLS_MP_LOG_CAT);
			JLog::add("Access token: {$accessToken}", JLog::DEBUG, DLS_MP_LOG_CAT);
			JLog::add("Back URLs: {$backUrlsString}", JLog::DEBUG, DLS_MP_LOG_CAT);
			JLog::add("Redirect timeout: {$this->redirectTimeout}", JLog::DEBUG, DLS_MP_LOG_CAT);
			JLog::add("+------------------------------------------------------------+", JLog::DEBUG, DLS_MP_LOG_CAT);
		}
	}

	/**
	 * Creates a custom log
	 */
	private function createCustomLog() {
		// Add a logger
		JLog::addLogger(
			array(
					// Sets file name
					'text_file' => 'dls_mercadopago.log.php'
			),
			// Sets messages of all log levels to be sent to the file.
			JLog::ALL,
			// The log category/categories which should be recorded in this file.
			// In this case, it's just the one category from our extension.
			// We still need to put it inside an array.
			array(DLS_MP_LOG_CAT)
		);
	}

	/**
	 * Sets the backUrls object getting the data from the plugin settings
	 */
	private function setBackUrls () {
		$app    = JFactory::getApplication();
		$Itemid = $app->input->getInt('Itemid', 0); // Joomla Itemid (menu item id)

		// Get back urls parameters
		$successBackUrl = $this->params->get('back_urls_success', $this->getReturnUrl('complete', $Itemid));
		$pendingBackUrl = $this->params->get('back_urls_pending', '');

		// TODO: From where do we get the message from MP on failure?
		$failureReason = $this->params->get('default_failure_reason', 'No pudo realizarse el pago');
		$failureQuery = 'failReason='. urlencode($failureReason);
		$failureBackUrl = $this->params->get('back_urls_failure', $this->getReturnUrl('failure', $Itemid, $failureQuery));

		if (!empty($successBackUrl) || !empty($pendingBackUrl) || !empty($failureBackUrl)) {
			// Create a new object 
			$this->backUrls = new stdClass();

			if (!empty($successBackUrl)) {
				$this->backUrls->success = $successBackUrl;
			}
			if (!empty($pendingBackUrl)) {
				$this->backUrls->pending = $pendingBackUrl;
			}
			if (!empty($failureBackUrl)) {
				$this->backUrls->failure = $failureBackUrl;
			}
		}
	}

	/**
	 * Process Payment
	 *
	 * @param OSMembershipTableSubscriber $row The row of the Table Subscriber
	 * @param array                       $data The data from the subscription form
	 */
	public function processPayment($row, $data)
	{
		/// Get a row from the database with the membership data
		$rowPlan = OSMembershipHelperDatabase::getPlan($row->plan_id);

		// Crea un objeto de preferencia
		$preference = new MercadoPago\Preference();

		// Save the id of the subscrition to be used in IPN verification
		$preference->external_reference = strval($row->id);

		$payer = new MercadoPago\Payer();
		$payer->name = $row->first_name;
		$payer->surname = $row->last_name;
		$payer->first_name = $row->first_name;
		$payer->last_name = $row->last_name;
		$payer->email = $row->email;
		$payer->address = array(
			"street_name" => $row->address,
			"street_number" => "",
			"zip_code" => $row->zip
		);
		$payer->phone = array(
			"area_code" => "",
			"number" => $row->phone
		);

		$preference->payer = $payer;

		// Crea un ítem para la preferencia
		// Ver https://www.mercadopago.com.ar/developers/es/guides/payments/web-payment-checkout/advanced-integration/
		$item = new MercadoPago\Item();
		$item->id = strval($row->id); // The subscription id should be a string to MP
		$item->title = $data['item_name'];
		$item->description = $rowPlan->short_description; // The short membership plan description
		$item->quantity = 1;
		$item->unit_price = round($data['amount'], 2);
		$item->currency_id = $data['currency'];

		// Add the item to the preference
		$preference->items = array($item);

		// Add back urls if its values are not empty
		if (!is_null($this->backUrls)) {
			$preference->back_urls = $this->backUrls;
		}

		$preference->auto_return = "approved";
		// $preference->auto_return = "all";

		// notification_url is used for web hooks
		// $preference->notification_url = $this->getPaymentNotificationURL();

		// Save the preference
		$preference->save();

		// Set the url
		$this->url = $preference->init_point;

		// Log data if it is required
		if($this->debugLog)
		{
			$payerString = print_r($payer, true);
			$itemString = print_r($item, true);
			$backUrlsString = print_r($preference->back_urls, true);

			JLog::add("dls_mercadopago processPayment", JLog::DEBUG, DLS_MP_LOG_CAT);
			JLog::add("External reference: {$preference->external_reference}", JLog::DEBUG, DLS_MP_LOG_CAT);
			JLog::add("Payer: {$payerString}", JLog::DEBUG, DLS_MP_LOG_CAT);
			JLog::add("Item: {$itemString}", JLog::DEBUG, DLS_MP_LOG_CAT);
			JLog::add("Init point: {$preference->init_point}", JLog::DEBUG, DLS_MP_LOG_CAT);
			JLog::add("Notification URL: {$preference->notification_url}", JLog::DEBUG, DLS_MP_LOG_CAT);
			JLog::add("Back URLs: {$backUrlsString}", JLog::DEBUG, DLS_MP_LOG_CAT);
		}

		// We have replaced the call to $this->renderRedirectForm() for our own render function
		// because the needs a GET request instead a POST request.
		$this->renderMercadoPagoRedirectForm($preference->init_point, array());
	}

	/**
	 * Include javascript or CSS files.
	 * The files are searched in the folders media/dls_mercadopago/js and media/dls_mercadopago/css
	 * 
	 * @param string $fileType The type of file to include. It has to be 'css' or 'js'
	 * @return bool True if any file was included
	 */
	private function includeFiles($fileType) {
		if (empty($fileType)) {
			return false;
		}
		// Plugin media folder
		$mediaPluginFolder = JPATH_ROOT.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'dls_mercadopago';
		// Check if the plugin media folder exists
		if (!file_exists($mediaPluginFolder)) {
			return false;
		}
		$fileTypeFolder = $mediaPluginFolder.DIRECTORY_SEPARATOR.$fileType;
		// Check if the plugin javascript folder exists
		if (!file_exists($fileTypeFolder)) {
			return false;
		}
		// Read the filenmaes in the folder
		$files = scandir($fileTypeFolder);
		if (count($files) <= 2) {
			return false;
		}
		// Get the Joomla Document
		$document = JFactory::getDocument();

		// Iterate files to include them
		foreach ($files as $filename) {
			// ignore . and .. filenames
			if ($filename === '.' || $filename === '..') {
				continue;
			}
			// The media url for the current file
			$pluginMediaUrl = "/media/dls_mercadopago/{$fileType}/{$filename}";

			switch($fileType) {
				case 'css':
					$document->addStyleSheet($pluginMediaUrl);
					break;
				case 'js':
					// Pass data to javascript
					$document->addScriptDeclaration("window.dls_mercadopago = { redirectTimeout: $this->redirectTimeout, url: '$this->url' };");
					// Add javascript files to the header
					$document->addScript($pluginMediaUrl, array(), array('defer' => 'defer') );
					break;
				default:
			}
		}
		return true;
	}

	/**
	 * Renders a layout if it exists
	 * 
	 * @param string $layoutName The name of layout to be rendered.
	 * @return string|boolean Returns a string with the result of render the layout or false if there is no layout.
	 */
	private function useLayout($layoutName) {
		if (empty($layoutName)) {
			return false;
		}
		// Plugin media folder
		$mediaPluginFolder = JPATH_ROOT.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'dls_mercadopago'.DIRECTORY_SEPARATOR.'layouts';
		// Check if the plugin media folder exists
		if (!file_exists($mediaPluginFolder)) {
			return false;
		}

		$layoutFile = $mediaPluginFolder.DIRECTORY_SEPARATOR."{$layoutName}.php";
		// Check if the plugin layout file exists
		if (!file_exists($layoutFile)) {
			return false;
		}

		$layout = new JLayoutFile($layoutName, $mediaPluginFolder);
		
		$data = new stdClass();
		$data->redirect_text = $this->params->get('redirect_text', DEFAULT_REDIRECT_TEXT);
		$data->redirect_nojs_text = $this->params->get('redirect_nojs_text', DEFAULT_REDIRECT_NOJS_TEXT);
		$data->redirect_link_text = $this->params->get('$redirect_link_text', DEFAULT_REDIRECT_LINK_TEXT);
		$data->redirect_link_title = $this->params->get('$redirect_link_title', DEFAULT_REDIRECT_LINK_TITLE);
		$data->url = $this->url;

		$html = $layout->render($data);

		return $html;
	}

	/**
	 * Renders the Mercado Pago redirect page
	 * 
	 * @param string $url Mercado pago init point URL.
	 */
	public function renderMercadoPagoRedirectForm($url) {

		// Check if a layout to render instead de default one
		$layout = $this->useLayout('dls_mercadopago');
		if ($layout !== false) {
			echo $layout;
			return;
		}

		$redirect_text = $this->params->get('redirect_text', DEFAULT_REDIRECT_TEXT);
		$redirect_nojs_text = $this->params->get('redirect_nojs_text', DEFAULT_REDIRECT_NOJS_TEXT);
		$redirect_link_text = $this->params->get('$redirect_link_text', DEFAULT_REDIRECT_LINK_TEXT);
		$redirect_link_title = $this->params->get('$redirect_link_title', DEFAULT_REDIRECT_LINK_TITLE);

		// If there is any custom CSS file, include it
		$this->includeFiles('css');
		// If there is any custom JS file, include it
		$jsIncluded = $this->includeFiles('js');
		?>
		<div class="payment-heading"><?php echo $redirect_text; ?></div>
		<form method="get" action="<?php echo $url; ?>" name="payment_form" id="payment_form">

			<div class="dls-mp-container">
				<div class="dls-mp-link-container">
					<span class="dls-mp-nojs-text"><?php echo $redirect_nojs_text; ?></span>
					<a id="dls-mp-link" class="dls-mp-link" href="<?php echo $url ?>" title="<?php echo $redirect_link_title; ?>">
						<span class="dls-mp-link-text"><?php echo $redirect_link_text; ?></span>
					</a>
				</div>
			</div>

			<?php if (!$jsIncluded) : ?>
				<script type="text/javascript">
					setTimeout(function() { window.location.href = "<?php echo $url; ?>"}, <?php echo $this->redirectTimeout; ?>);
				</script>
			<?php endif; ?>
		</form>
	<?php
	}

	/**
	 * Verify payment
	 *
	 * @return bool
	 */
	public function verifyPayment()
	{

		$validateResult = false;

		if ($this->ipnLog) {
			JLog::add("+-------------------- MP Verify Payment starts... --------------------+", JLog::DEBUG, DLS_MP_LOG_CAT);
			// record all the info in the log for debugging
			$validateResult = $this->validateWithLog();
		} else {
			// Do not record anything for more performance
			$validateResult = $this->validate();
		}

		if (!$validateResult) {
			if($this->ipnLog) {
				JLog::add("+-------------------- MP Verify Payment finish with a failed validation --------------------+", JLog::DEBUG, DLS_MP_LOG_CAT);
			}
			// Do noting if cannot validate the request.
			return false;
		}
		// Check if there is an external reference in the MercadoPago payment
		// If external reference is empty, there is no way to update the subscription.
		// The external reference is the subscription id
		if(empty($this->merchant_order->external_reference)) {
			if($this->ipnLog) {
				JLog::add("+-------------------- MP Verify Payment finish with because the external reference in the merchant order is empty! --------------------+", JLog::DEBUG, DLS_MP_LOG_CAT);
			}
			return false;
		}

		// Get the Subscriber id. It is an integer
		$id = intval($this->merchant_order->external_reference);
		if ($id === 0) {
			if($this->ipnLog) {
				JLog::add("+-------------------- MP Verify Payment finish because the external reference in the merchant order has una invalid value!. id = {$id} --------------------+", JLog::DEBUG, DLS_MP_LOG_CAT);
			}
			return false;
		}

		// Use the payment id as transactionId
		$transactionId = $this->merchant_order->id;

		// Get the Joomla table 
		$row = JTable::getInstance('OsMembership', 'Subscriber');

		// Load the row with the subscriber id (MP external reference)
		if (!$row->load($id))
		{
			if($this->ipnLog) {
				JLog::add("+-------------------- MP Verify Payment finish because cannot find the Subscirber with id = {$id} --------------------+", JLog::DEBUG, DLS_MP_LOG_CAT);
			}
			// Something was wrong, cannot find the subscriber row in the table
			return false;
		}

		// If the subsctiption is active, it was processed before, return false
		if ($row->published)
		{
			if($this->ipnLog) {
				JLog::add("+-------------------- MP Verify Payment finish because The row with id = {$id} already was processed --------------------+", JLog::DEBUG, DLS_MP_LOG_CAT);
			}
			return false;
		}

		// Check and make sure the transaction is only processed one time
		if ($transactionId && OSMembershipHelper::isTransactionProcessed($transactionId))
		{
			if($this->ipnLog) {
				JLog::add("+-------------------- MP Verify Payment finish because The transaction with transaction id = {$transactionId} is ignored, it has already been processed previously --------------------+", JLog::DEBUG, DLS_MP_LOG_CAT);
			}
			return false;
		}

		if($this->ipnLog) {
			JLog::add("+-------------------- MP Verify Payment finish sucessfully --------------------+", JLog::DEBUG, DLS_MP_LOG_CAT);
		}

		// This will final the process, set subscription status to active, trigger onMembershipActive event, sending emails to subscriber and admin...
		$this->onPaymentSuccess($row, $transactionId);
	}

	protected function validateWithLog() {
		// This code is based on https://www.mercadopago.com.ar/developers/es/guides/notifications/ipn/

		JLog::add("+-------------------- MP Validate With Log --------------------+", JLog::DEBUG, DLS_MP_LOG_CAT);
		JLog::add("Validate starts...", JLog::DEBUG, DLS_MP_LOG_CAT);
		// Log the request from mercado pago
		$getString = print_r($_GET, true);
		JLog::add("MercadoPago GET Request: $getString", JLog::DEBUG, DLS_MP_LOG_CAT);

		// Mercado Pago Merchant Order
		$this->merchant_order = null;

		try {
			// Check the topic
			switch($_GET["topic"]) {
				case "payment":
						JLog::add("Finding the payment using MercadoPago SDK...", JLog::DEBUG, DLS_MP_LOG_CAT);
						// Get the payment from MercadoPago
						$this->payment = MercadoPago\Payment::find_by_id($_GET["id"]);

						// Check if the payment was found
						if (empty($this->payment) || is_null($this->payment)) {
							JLog::add("Invalid payment: cannot find the payment data!. topic= {$_GET["topic"]}; id = {$_GET["id"]}", JLog::DEBUG, DLS_MP_LOG_CAT);
							return false;
						}

						// Print debugging information
						$paymentDebugInfo = print_r($this->payment, true);
						JLog::add("Payment object found: {$paymentDebugInfo}", JLog::DEBUG, DLS_MP_LOG_CAT);

						JLog::add("Trying to find the merchant order with id =  {$this->payment->order->id}...", JLog::DEBUG, DLS_MP_LOG_CAT);
						// Get the merchant_order from MercadoPago
						// Merchant order an entity that groups both payments and shipments
						$this->merchant_order = MercadoPago\MerchantOrder::find_by_id($this->payment->order->id);
					break;
				case "merchant_order":
				JLog::add("Trying to find the merchant order with id =  {$_GET["id"]}...", JLog::DEBUG, DLS_MP_LOG_CAT);
						// Get the merchant order from MercadoPago
						// Merchant order an entity that groups both payments and shipments
						$this->merchant_order = MercadoPago\MerchantOrder::find_by_id($_GET["id"]);
					break;
				default:
					// TODO: Are there other topics?
					JLog::add("Validate Payment failure: unknown topic. Topic = {$_GET["topic"]}", JLog::DEBUG, DLS_MP_LOG_CAT);
					return false; // Unknown topic
			}
		} catch (Exception $e) {
			// Something was wrong, an exception was throwed
			$errorMsg = "MercadoPago has thrown an exception: {$e->getMessage()}";
			JLog::add("Validate payment failure, exception was thrown", JLog::DEBUG, DLS_MP_LOG_CAT);
			JLog::add($errorMsg, JLog::DEBUG, DLS_MP_LOG_CAT);
			return false;
		}

		// Check if merchant order was found
		if(empty($this->merchant_order) || is_null($this->merchant_order)) {
			JLog::add("Invalid payment: cannot find the merchant order data!. id = {$_GET["id"]}", JLog::DEBUG, DLS_MP_LOG_CAT);
			return false;
		}

		// Print debugging information
		$merchantOrderDebugInfo = print_r($this->merchant_order, true);
		JLog::add("Merchant order found: {$merchantOrderDebugInfo}", JLog::DEBUG, DLS_MP_LOG_CAT);

		JLog::add("Calculating the paid amount...", JLog::DEBUG, DLS_MP_LOG_CAT);
		// The total paid amount for all items
    $paid_amount = 0;
    foreach ($this->merchant_order->payments as $payment) {
        if ($payment->status == 'approved'){
            $paid_amount += $payment->transaction_amount;
        }
		}
		
		// If the payment's transaction amount is equal (or bigger) than the merchant_order's amount you can release your items
    if($paid_amount < $this->merchant_order->total_amount){
			JLog::add("Validate Payment: Not paid yet. Do not release your item. Paid amount: {$paid_amount} / Total amount: {$this->merchant_order->total_amount}", JLog::DEBUG, DLS_MP_LOG_CAT);
			return false;
		}

		JLog::add("Validate Payment ok!, paid amount = {$paid_amount}; total amount = {$this->merchant_order->total_amount}", JLog::DEBUG, DLS_MP_LOG_CAT);
		return true;

	}

	/**
	 * Validate the post data from Payment gateway to our server
	 *
	 * @return string
	 */
	protected function validate()
	{
	
		// This code is based on https://www.mercadopago.com.ar/developers/es/guides/notifications/ipn/

		// Mercado Pago Merchant Order
		$this->merchant_order = null;

		try {
			// Check the topic
			switch($_GET["topic"]) {
				case "payment":
						// Get the payment from MercadoPago
						$this->payment = MercadoPago\Payment::find_by_id($_GET["id"]);

						// Check if the payment was found
						if (empty($this->payment) || is_null($this->payment)) {
							return false;
						}

						// Get the merchant_order from MercadoPago
						// Merchant order an entity that groups both payments and shipments
						$this->merchant_order = MercadoPago\MerchantOrder::find_by_id($payment->order->id);
					break;
				case "merchant_order":
						// Get the merchant order from MercadoPago
						// Merchant order an entity that groups both payments and shipments
						$this->merchant_order = MercadoPago\MerchantOrder::find_by_id($_GET["id"]);
					break;
				default:
					// TODO: Are there other topics?
					return false; // Unknown topic
			}
		} catch (Exception $e) {

			// Something was wrong, an exception was throwed
			$errorMsg = "MercadoPago has thrown an exception: {$e->getMessage()}";
			JLog::add($errorMsg, JLog::ERROR, 'mercado-pago-exception');
			return false;
		}

		// Check if merchant order was found
		if(empty($this->merchant_order) || is_null($this->merchant_order)) {
			return false;
		}

		// The total paid amount for all items
    $paid_amount = 0;
    foreach ($this->merchant_order->payments as $payment) {
        if ($payment->status == 'approved'){
            $paid_amount += $payment->transaction_amount;
        }
    }

    // If the payment's transaction amount is equal (or bigger) than the merchant_order's amount you can release your items
    if($paid_amount < $this->merchant_order->total_amount){
			return false;
		}
		// Validate the callback data, return true if it is valid and false otherwise
		return true;
	}

	/**
	 * Get SEF return URL
	 *
	 * @param string $view Mebership View name
	 * @param int $Itemid Joomla menu item
	 * @param string $query URI query to add at the end
	 *
	 * @return string
	 */
	protected function getReturnUrl($view, $Itemid, $query='')
	{
		$rootURL    = rtrim(JUri::root(), '/');
		$subpathURL = JUri::root(true);

		if (!empty($subpathURL) && ($subpathURL != '/'))
		{
			$rootURL = substr($rootURL, 0, -1 * strlen($subpathURL));
		}
		$returnUrl = $rootURL . JRoute::_(OSMembershipHelperRoute::getViewRoute($view, $Itemid), false);
		
		if (!empty($query)) {
			$returnUrl .= '&' . $query;
		}
		return $returnUrl;

	}

	protected function getPaymentNotificationURL() {
		$rootURL = JUri::root(false);
		// Get the plugin name
		$className = get_class($this);
		// The payment_method have to be the plugin name
		return "{$rootURL}index.php?option=com_osmembership&task=payment_confirm&payment_method={$className}";
	}
}