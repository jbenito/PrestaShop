<?php
/*
* 2007-2013 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2013 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_'))
	exit;

class Dashtrends extends Module
{
	protected $dashboard_data;
	protected $dashboard_data_compare;
	protected $dashboard_data_sum;
	protected $dashboard_data_sum_compare;

	public function __construct()
	{
		$this->name = 'dashtrends';
		$this->displayName = 'Dashboard Trends';
		$this->tab = '';
		$this->version = '0.1';
		$this->author = 'PrestaShop';

		parent::__construct();
	}

	public function install()
	{
		if (!parent::install() || !$this->registerHook('dashboardZoneTwo') || !$this->registerHook('dashboardData') || !$this->registerHook('displayBackOfficeHeader'))
			return false;
		return true;
	}
	
	public function hookDisplayBackOfficeHeader()
	{
		if (get_class($this->context->controller) == 'AdminDashboardController')
			$this->context->controller->addJs($this->_path.'views/js/'.$this->name.'.js');
	}

	public function hookDashboardZoneTwo($params)
	{
		return $this->display(__FILE__, 'dashboard_zone_two.tpl');
	}

	protected function getData($date_from, $date_to)
	{
		// We need the following figures to calculate our stats
		$tmp_data = array(
			'visits_score' => array(),
			'orders_score' => array(),
			'total_paid_tax_excl' => array(),
			'total_discounts_tax_excl' => array(),
			'total_credit_tax_excl' => array(),
			'total_credit_shipping_tax_excl' => array(),
			'total_product_price_tax_excl' => array(),
			'total_purchase_price' => array()
		);

		// The visits are retrieved from Analytics if available
		$gapi = Module::isInstalled('gapi') ? Module::getInstanceByName('gapi') : false;
		if (Validate::isLoadedObject($gapi) && $gapi->isConfigured())
		{
			if ($result = $gapi->requestReportData('ga:date', 'ga:visits', $date_from.' 00:00:00', $date_to.' 23:59:59', null, null, 1, 9999))
				foreach ($result as $row)
					$tmp_data['visits_score'][strtotime(preg_replace('/^([0-9]{4})([0-9]{2})([0-9]{2})$/', '$1-$2-$3', $row['dimensions']['date']))] = $row['metrics']['visits'];
		}
		else
		{
			$result = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
			SELECT
				LEFT(`date_add`, 10) as date,
				COUNT(*) as visits_score
			FROM `'._DB_PREFIX_.'connections`
			WHERE `date_add` BETWEEN "'.pSQL($date_from).' 00:00:00" AND "'.pSQL($date_to.' 23:59:59').'"
			'.Shop::addSqlRestriction(false).'
			GROUP BY LEFT(`date_add`, 10)');
			foreach ($result as $row)
				$tmp_data['visits_score'][strtotime($row['date'])] = $row['visits_score'];
		}

		$result = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
		SELECT
			LEFT(`invoice_date`, 10) as date,
			COUNT(*) as orders_score,
			SUM(`total_paid_tax_excl` / `conversion_rate`) as total_paid_tax_excl,
			SUM(`total_discounts_tax_excl` / `conversion_rate`) as total_discounts_tax_excl
		FROM `'._DB_PREFIX_.'orders`
		WHERE `invoice_date` BETWEEN "'.pSQL($date_from).' 00:00:00" AND "'.pSQL($date_to.' 23:59:59').'"
		'.Shop::addSqlRestriction(Shop::SHARE_ORDER).'
		GROUP BY LEFT(`invoice_date`, 10)');
		foreach ($result as $row)
			foreach (array('orders_score', 'total_paid_tax_excl', 'total_discounts_tax_excl') as $var)
				$tmp_data[$var][strtotime($row['date'])] = $row[$var];

		$result = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
		SELECT
			LEFT(os.`date_add`, 10) as date,
			SUM(os.`amount` / o.`conversion_rate`) as total_credit_tax_excl,
			SUM(os.`shipping_cost_amount` / o.`conversion_rate`) as total_credit_shipping_tax_excl
		FROM `'._DB_PREFIX_.'orders` o
		LEFT JOIN `'._DB_PREFIX_.'order_slip` os ON o.id_order = os.id_order
		WHERE os.`date_add` BETWEEN "'.pSQL($date_from).' 00:00:00" AND "'.pSQL($date_to.' 23:59:59').'"
		'.Shop::addSqlRestriction(Shop::SHARE_ORDER, 'o').'
		GROUP BY LEFT(os.`date_add`, 10)');
		foreach ($result as $row)
			foreach (array('total_credit_tax_excl', 'total_credit_shipping_tax_excl') as $var)
				$tmp_data[$var][strtotime($row['date'])] = $row[$var];

		$result = Db::getInstance(_PS_USE_SQL_SLAVE_)->ExecuteS('
		SELECT
			LEFT(`invoice_date`, 10) as date,
			SUM(od.`total_price_tax_excl` / `conversion_rate`) as total_product_price_tax_excl,
			SUM(od.`product_quantity` * od.`purchase_supplier_price` / `conversion_rate`) as total_purchase_price
		FROM `'._DB_PREFIX_.'orders` o
		LEFT JOIN `'._DB_PREFIX_.'order_detail` od ON o.id_order = od.id_order
		WHERE `invoice_date` BETWEEN "'.pSQL($date_from).' 00:00:00" AND "'.pSQL($date_to.' 23:59:59').'"
		'.Shop::addSqlRestriction(Shop::SHARE_ORDER, 'o').'
		GROUP BY LEFT(`invoice_date`, 10)');
		foreach ($result as $row)
			foreach (array('total_product_price_tax_excl', 'total_purchase_price') as $var)
				$tmp_data[$var][strtotime($row['date'])] = $row[$var];

		return $tmp_data;
	}
	
	protected function refineData($date_from, $date_to, $gross_data)
	{
		$refined_data = array(
			'sales' => array(),
			'orders' => array(),
			'average_cart_value' => array(),
			'visits' => array(),
			'conversion_rate' => array(),
			'net_profits' => array()
		);
		$from = max(strtotime(_PS_CREATION_DATE_.' 00:00:00'), strtotime($date_from.' 00:00:00'));
		$to = min(time(), strtotime($date_to.' 23:59:59'));
		for ($date = $from; $date <= $to; $date = strtotime('+1 day', $date))
		{
			$refined_data['sales'][$date] = 0;
			if (isset($gross_data['total_paid_tax_excl'][$date]))
				$refined_data['sales'][$date] += $gross_data['total_paid_tax_excl'][$date];
			if (isset($gross_data['total_credit_tax_excl'][$date]))
				$refined_data['sales'][$date] -= $gross_data['total_credit_tax_excl'][$date];
			if (isset($gross_data['total_credit_shipping_tax_excl'][$date]))
				$refined_data['sales'][$date] -= $gross_data['total_credit_shipping_tax_excl'][$date];
				
			$refined_data['orders'][$date] = isset($gross_data['orders_score'][$date]) ? $gross_data['orders_score'][$date] : 0;
			
			$refined_data['average_cart_value'][$date] = $refined_data['orders'][$date] ? $refined_data['sales'][$date] / $refined_data['orders'][$date] : 0;
			
			$refined_data['visits'][$date] = isset($gross_data['visits_score'][$date]) ? $gross_data['visits_score'][$date] : 0;
			
			$refined_data['conversion_rate'][$date] = $refined_data['visits'][$date] ? $refined_data['orders'][$date] / $refined_data['visits'][$date] : 0;

			$refined_data['net_profits'][$date] = 0;
			if (isset($gross_data['total_product_price_tax_excl'][$date]))
				$refined_data['net_profits'][$date] += $gross_data['total_product_price_tax_excl'][$date];
			if (isset($gross_data['total_discounts_tax_excl'][$date]))
				$refined_data['net_profits'][$date] -= $gross_data['total_discounts_tax_excl'][$date];
			if (isset($gross_data['total_purchase_price'][$date]))
				$refined_data['net_profits'][$date] -= $gross_data['total_purchase_price'][$date];
			if (isset($gross_data['total_credit_tax_excl'][$date]))
				$refined_data['net_profits'][$date] -= $gross_data['total_credit_tax_excl'][$date];
		}
		return $refined_data;
	}
	
	protected function addupData($data)
	{
		$summing = array(
			'sales' => 0,
			'orders' => 0,
			'average_cart_value' => 0,
			'visits' => 0,
			'conversion_rate' => 0,
			'net_profits' => 0
		);

		$summing['sales'] = array_sum($data['sales']);
		$summing['orders'] = array_sum($data['orders']);
		$summing['average_cart_value'] = $summing['sales'] ? $summing['sales'] / $summing['orders'] : 0;
		$summing['visits'] = array_sum($data['visits']);
		$summing['conversion_rate'] = $summing['visits'] ? $summing['orders'] / $summing['visits'] : 0;
		$summing['net_profits'] = array_sum($data['net_profits']);
		return $summing;
	}

	protected function compareData($data1, $data2)
	{
		return array(
			'sales_score_trends' => array(
				'way' => ($data1['sales'] == $data2['sales'] ? 'right' : ($data1['sales'] > $data2['sales'] ? 'up' : 'down')),
				'value' => ($data1['sales'] > $data2['sales'] ? '+' : '').($data2['sales'] ? round(100 * $data1['sales'] / $data2['sales'] - 100, 2).'%' : '&infin;')
			),
			'orders_score_trends' => array(
				'way' => ($data1['orders'] == $data2['orders'] ? 'right' : ($data1['orders'] > $data2['orders'] ? 'up' : 'down')),
				'value' => ($data1['orders'] > $data2['orders'] ? '+' : '').($data2['orders'] ? round(100 * $data1['orders'] / $data2['orders'] - 100, 2).'%' : '&infin;')
			),
			'cart_value_score_trends' => array(
				'way' => ($data1['average_cart_value'] == $data2['average_cart_value'] ? 'right' : ($data1['average_cart_value'] > $data2['average_cart_value'] ? 'up' : 'down')),
				'value' => ($data1['average_cart_value'] > $data2['average_cart_value'] ? '+' : '').($data2['average_cart_value'] ? round(100 * $data1['average_cart_value'] / $data2['average_cart_value'] - 100, 2).'%' : '&infin;')
			),
			'visits_score_trends' => array(
				'way' => ($data1['visits'] == $data2['visits'] ? 'right' : ($data1['visits'] > $data2['visits'] ? 'up' : 'down')),
				'value' => ($data1['visits'] > $data2['visits'] ? '+' : '').($data2['visits'] ? round(100 * $data1['visits'] / $data2['visits'] - 100, 2).'%' : '&infin;')
			),
			'conversion_rate_score_trends' => array(
				'way' => ($data1['conversion_rate'] == $data2['conversion_rate'] ? 'right' : ($data1['conversion_rate'] > $data2['conversion_rate'] ? 'up' : 'down')),
				'value' => ($data1['conversion_rate'] > $data2['conversion_rate'] ? '+' : '').($data2['conversion_rate'] ? round($data1['visits_score'] - $data2['visits_score'], 2).$this->l('pts') : '&infin;')
			),
			'net_profits_score_trends' => array(
				'way' => ($data1['net_profits'] == $data2['net_profits'] ? 'right' : ($data1['net_profits'] > $data2['net_profits'] ? 'up' : 'down')),
				'value' => ($data1['net_profits'] > $data2['net_profits'] ? '+' : '').($data2['net_profits'] ? round(100 * $data1['net_profits'] / $data2['net_profits'] - 100, 2).'%' : '&infin;')
			)
		);
	}
	
	public function hookDashboardData($params)
	{
		// Artificially remove the decimals in order to get a cleaner Dashboard
		$currency = clone $this->context->currency;
		$currency->decimals = 0;

		// Retrieve, refine and add up data for the selected period
		$tmp_data = $this->getData($params['date_from'], $params['date_to']);
		$this->dashboard_data = $this->refineData($params['date_from'], $params['date_to'], $tmp_data);
		$this->dashboard_data_sum = $this->addupData($this->dashboard_data);

		// Retrieve, refine and add up data for the comparison period
		$tmp_data_compare = $this->getData($params['compare_from'], $params['compare_to']);
		$this->dashboard_data_compare = $this->refineData($params['compare_from'], $params['compare_to'], $tmp_data_compare);
		$this->dashboard_data_sum_compare = $this->addupData($this->dashboard_data_compare);
		
		$data_trends = $this->compareData($this->dashboard_data_sum, $this->dashboard_data_sum_compare);

		return array(
			'data_value' => array(
				'sales_score' => Tools::displayPrice(round($this->dashboard_data_sum['sales']), $currency),
				'orders_score' => $this->dashboard_data_sum['orders'],
				'cart_value_score' => Tools::displayPrice($this->dashboard_data_sum['average_cart_value'], $currency),
				'visits_score' => $this->dashboard_data_sum['visits'],
				'conversion_rate_score' => round(100 * $this->dashboard_data_sum['conversion_rate'], 2).'%',
				'net_profits_score' => Tools::displayPrice(round($this->dashboard_data_sum['net_profits']), $currency),
			),
			'data_trends' => $data_trends,
			'data_chart' => array('dash_trends_chart1' => $this->getChartTrends()),
		);
	}
	
	public function getChartTrends()
	{
		$chart_data = array();
		foreach (array_keys($this->dashboard_data) as $chart_key)
		{
			$calibration = 1;
			foreach ($this->dashboard_data[$chart_key] as $value)
				if ($value)
				{
					$calibration = $value;
					break;
				}
			$chart_values = array();
			foreach ($this->dashboard_data[$chart_key] as $key => $value)
				$chart_values[] = array(1000 * $key, 100 * $value / $calibration);
			$chart_data[$chart_key] = $chart_values;
		}

		return array(
			'chart_type' => 'line_chart_trends',
			'data' => array(
				array(
					'key' => $this->l('Sales'),
					'values' => $chart_data['sales']
				),
				array(
					'key' => $this->l('Orders'),
					'values' => $chart_data['orders']
				),
				array(
					'key' => $this->l('Average Cart Value'),
					'values' => $chart_data['average_cart_value']
				),
				array(
					'key' => $this->l('Visits'),
					'values' => $chart_data['visits']
				),
				array(
					'key' => $this->l('Conversion Rate'),
					'values' => $chart_data['conversion_rate']
				),
				array(
					'key' => $this->l('Net Profits'),
					'values' => $chart_data['net_profits']
				)
			)
		);
	}
}