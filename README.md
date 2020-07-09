# Joomla! support for Expose

A plugin which allows local Joomla 3 and 4 sites to be served over the web using Expose by BeyondCode.

## Instructions

Create a ZIP file with the contents of the `plugins/system/expose` folder of this repository. Install it in Joomla like any other extension.

Go to Extensions, Manage, Plugins.
 
Filter by plugins in the `system` folder.

Move the “System – Expose” plugin all the way to the top. Then, enable it.

You can now share your local site with Expose e.g. `expose share http://localhost/mysite`

## Why you need this plugin

### Executive summary

If you don't use this plugin and you don't change the `$live_site` in your `configuration.php` every time you use Expose you cannot share a Joomla! site with Expose. This plugin makes things easier for you but is NOT a requisite for using Expose with Joomla.

### Gory, technical details

Expose works by creating a tunnel between your local web server and the Expose server. When you access the shared subdomain name in the Expose server what really happens is the local Expose application accessing your server, transferring the response to the remote Expose server which then sends it back to your browser.

As far as Joomla is concerned, the request came from a local browser running on your computer, accessing the local site's domain. It does _not_ see the Expose subdomain. Here lies the problem.

Joomla always uses the hostname reported by the web server to determine the fully qualified domain (subdomain, domain and TLD) it runs under. This information is used to determine the base and root URLs of your site. All absolute links, media files etc include the base site URL.

Since Joomla only ever sees its _local server_ domain name the links and media files will appear to be ‘broken’. Fixing that typically requires changing Joomla's `confiugration.php` file by hand, setting the `$live_site` to the domain name you will be accessing your site under, i.e. the temporary Expose subdomain. 

This is a pain in the rear. If you forget to change it before sharing your site with Expose you'll be scratching your head as to why your site appears broken. If you forget to change it back to an empty string after you're done using Expose you will have the same problem, your site will appear broken. There must surely be a better way, no?

Yes, there is. Using this plugin.

This plugin automatically detects if your site is being shared with Expose. In this case **and this case only** it will use the temporary Expose subdomain made known to us by Expose through HTTP headers. It does three things with it:

* It updates the PHP `$_SERVER` superglobal for the duration of the request.
* It updates the `live_site` global configuration parameter in memory, only for the duration of the request.
* It updates Joomla's Uri class internals, where the base and root URLs are read from.

This works with all extensions which are written according to Joomla's best coding practices and even most of the extensions which are not. The only extensions which won't work are those using PHP's `filter_input` because PHP initializes the data for these functions before our plugin has the chance to run.

In simple terms, publishing this plugin will _magically_ make your Joomla site work with Expose in 99.9% of cases.

## Performance and security impact

### Executive summary

The plugin itself causes a sub-millisecond performance impact but sharing your locally hosted Joomla site through Expose is _slow_. 

This plugin is meant to be installed on a site hosted on a local server which is not directly connected to the Internet. If you use it on a live site, directly accessible from the Internet without going through Expose, there are security concerns. However, there are technical measures to automatically make the plugin inert on live sites to mitigate these concerns.

### Gory, technical details

The performance impact of this plugin is negligible, in the area of a fraction of a millisecond. That said, accessing your site through Expose is _slow_ since every request (even for static resources) has to do the roundtrip browser, Expose server, local server, Expose server and back to the browser. You will see that your site loads as though you're on a slow 3G connection even if you have very fast WiFi. It's not an artefact of the plugin but rather how Expose itself works.

As far as security goes, the plugin has two options which make it inert on a live site to mitigate any potential risks (more on those risks later). By default, the “Only for private network IPs” option is enabled. This makes the plugin inert if your site is being accessed on a hostname that resolves to an IP address outside the localhost and private IP address space.

Now let's see what happens if you disable this option; or if you're on a weird host that sends the wrong hostname to Joomla _and_ you've not set up `$live_host` in your `configuration.php` (how your site works at all in this case is beyond me).

This plugin is explicitly designed to execute on _development sites hosted on your local server_ and for the sole intended purpose of facilitating the use of Expose. If you get it to execute on a live server you run the very real risk of your site being abused for phishing and / or malicious backlinks.

Here's the technical reasoning. If there's an `X-Exposed-By` HTTP header whose content begins with the string `Expose ` this plugin kicks in and honors the `X-Forwarded-Host` and `X-Forwarded-Proto` HTTP headers. This means that your site will believe it's being served under the domain name defined in the `X-Forwarded-Host` header and all of its links – including form submission URLs and media files – will point to that domain name. An attacker can easily abuse this to inject their own, malicious code in the page, create phishing pages or serve malicious links.

This is not an issue with a site hosted _on a local development server_ because any attack of this kind requires the server being directly exposed to the Internet and its domain name known to the attacker. The whole point of using Expose is that your local development server _is not_ connected directly to the Internet. Therefore, by definition, the only possible attack mode is not possible for your local development server. The problem can only occur on a _live_ server.

Please note that despite the technical measures in place to mitigate the security risks of accidentally using this plugin on a live host I very strongly recommend _NOT_ publishing this plugin on a live server. The technical measures in place are a last resort, much like an airbag. It's best if you don't enable this plugin on a live host just like it's much more preferable you do not drive into a wall in the first place.