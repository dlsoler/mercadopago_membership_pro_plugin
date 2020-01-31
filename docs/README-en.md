# Mercado Pago payment plugin for Membership Pro

This plugin for Membership Pro is to make payments using the payment gateway of **MercadoPago**.

Of course, you need an account on **MercadoPago** from where you can obtain the credentials to configure the plugin.

## Plugin Installation

1.  Click on Components -> Membership Pro -> Payments plugins.

2.  Click on "Choose file" button.

3.  Select the plugin file.

4.  Click on "Install" buttom.

---

## Configuration

Once the plugin was installed, click on the link corresponding to Mercado Pago plugin, then you can fill the settings.

* The tokens have to be obtained from MercadoPago credentials: <https://www.mercadopago.com/mla/account/credentials>

* If you want, you can fill out the back URLs fields to offer custom return pages to your website from MercadoPago.

## **IPN** Configuration (**I**nstant **P**ayment **N**otification)

You have to set an **IPN URL** in MercadoPago:

<https://www.mercadopago.com.ar/ipn-notifications>

Such url is something like the following:

https://**YOUR-IPN-DOMAIN-NAME**/index.php?option=com_osmembership&task=payment_confirm&payment_method=dls_mercadopago

Where **YOUR-IPN-DOMAIN-NAME** is the domain where you website is waiting to receive the IPN requests from MercadoPago

![MercadoPago IPN configuration](mercadopago-ipn-config.jpg)

___

## Custom CSS files

You can include your own custom css files.

This files are loaded when the redirect page is rendered.

1.  Create a folder **dls_mercadopago/css** in Joomla media folder (/media).
2.  Copy your css files in this folder.
3.  When the redirect page is rendered all the files in the folder are loaded as CSS files.

---

## Custom Javascript files

You can include your own custom javascript files.

This files are loaded when the redirect page is rendered.

1.  Create a folder **dls_mercadopago/js** in Joomla media folder (/media).
2.  Copy your javascript files in this folder.
3.  When the redirect page is rendered all the files in the folder are loaded as javascript scripts.

---

## Custom layout file

You can create your own custom layout to render the redirect page.

Your layout will replace de default plugin layout.

1.  Create a folder **dls_mercadopago/layouts** in Joomla media folder (/media).
2.  Copy your layout file in this folder with the name **dls_mercadopago.php**.
3.  When the redirect page is rendered your layout will be loaded instead of the default layout of the plugin.

---

## Debugging

In the configuration there are settings to enable the debugging information on the log.

In the Joomla log directory there is a log file where this information is saved.

---

## Docs for developers

* MercadoPago: https://www.mercadopago.com.ar/developers/es/guides/payments/web-payment-checkout/introduction/

* Membership Pro: http://membershipprodoc.joomservices.com/developer-documentation/dev-payment-plugin

* IPN register: please read this post to find the URL where register the IPN URL: https://groups.google.com/forum/#!topic/mercadopago-developers/yaThxsMsHKo



---