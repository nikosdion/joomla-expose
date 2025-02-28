# Expose plugin for Joomla!

Use Joomla! with [Expose by BeyondCode](https://expose.dev) more easily.

[Downloads](https://github.com/nikosdion/joomla-expose/releases) • [Issues](https://github.com/nikosdion/joomla-expose/issues)

❗️ This plugin will only work on Joomla! 4 and later.

## Introduction

[Expose by BeyondCode](https://expose.dev) is a tool which allows you to share a site hosted on your computer that's not directly accessible from the Internet using a publicly accessible subdomain. You cna use their service, or even [host your own, private service](https://www.dionysopoulos.me/expose-your-local-web-server-to-the-internet.html). This is very useful when you want to show your client or a remote colleague the progress you have done on a local development site, test services which require OAuth2 authentication with a callback URL resolving to a public IP address, etc.

The problem is that since your local site is being accessed locally, Joomla only sees the local domain name, and the localhost IP address (`127.0.0.1` or `::1`). To address the former you need to edit `configuration.php` and set your `$live_site` to the subdomain you get from the Expose server you are connected to. To address the latter, you need to go to Joomla's Global Configuration, Server tab, and set Behind Load Balancer to Yes.

While possible, it's inconvenient and error-prone. You need to connect to the server to find out the subdomain you get, edit your configuration, edit Global Configuration do the work you need to do, then undo these changes. This beats the purpose of sharing your site easily with Expose.

This here plugin comes to fill in the gap, providing the missing ease of use. Just enable the plugin. When you're using Expose, your site will be accessible just fine over the Internet, and all URLs will use the subdomain provided by the Expose server. When you are done, there is nothing for you to do other than stop the Expose connection. If you forget the plugin enabled after transferring the site to a live server there's nothign to worry about (with the default configuration); the plugin will be effectively inert.

## Installation

You can download the latest published version from the [Releases](https://github.com/nikosdion/joomla-expose/releases) page. Please make sure that you are downloading the `plg_system_expose.zip` file, NOT the ~~joomla-expose.zip~~ file. The latter is automatically generated by GitHub, and contains the source code in this repository.

If you'd like to use the latest code in the repository, simply ZIP the contents of the `plugins/system/expose` folder.

Either way, go to your site's `administrator`, System, Install, Extensions and click the browse button. Find the ZIP file and double-click on it. The plugin is now installed.

Go to System, Manage, Plugins and find the “System - Expose” plugin. Publish (enable) the plugin, and you're set.

> 📝 For best results, it is strongly advised to publish this plugin **BEFORE** any other plugin.

### If you are using a security extension

Security extensions may apply IP blocks before this plugin runs. To avoid blocking yourself –and all local access– from your site you should go to Joomla's Global Configuration, Server tab, and set Behind Load Balancer to Yes.

If your security extension blocks access based on the domain name you are using to access your site, you may have to add your Expose domain name to the list of allowed domain names. 

### Additional information for users of Admin Tools Professional

The information under “If you are using a security extension” applies to you too.

If you do not want to enable the Behind Load Balancer global option you should go to Components, Admin Tools for Joomla, Web Application Firewall, Configure WAF, Auto-ban and disable the “IP blocking of repeat offenders” option.

Domain name access control is implemented in Components, Admin Tools for Joomla, Web Application Firewall, Configure WAF, Request Filtering, Allowed domains. For local development sites it is strongly advised to _remove_ all entries from this option. Otherwise, you may have to add the exact subdomain used by your Expose server, which can be quite complicated if you are using a third party Expose server which assigns a randomly generated subdomain. 

## Configuration

The plugin offers the following options:

**Local domain**. Optional. Enter the domain name you use to access your site _locally_ (usually: `localhost`). If this is non-empty, the plugin will only take effect if the site is being accessed by the Expose client software using this domain name.

**Only allow private network IP access**. Recommended to be enabled at all times. When enabled, the plugin will only take effect if the site is being accessed by the Expose client software using and IPv4 address that belongs to a private network, or using the localhost IPv4 (`127.0.0.1`) or IPv6 (`::1`) address. You only need to disable this option if you have assigned a non-private IP address to your local server, or when you are using Expose to access a publicly accessible site (why are you doing that?!).

**Strict Expose detection**. Recommended to be enabled at all times. When enabled, the plugin will only take effect when the `X-Exposed-By` HTTP header is present, and it starts with `Expose `. This prevents accidental activation of the plugin if another kind of forwarded access to your site takes place, e.g. a CDN, load balancer, caching proxy, etc. You only need to disable it if for whatever reason the `X-Exposed-By` HTTP header is not made available to PHP by your local web server.

## License Notice

Expose plugin for Joomla! – Use Joomla! with [Expose by BeyondCode](https://expose.dev) more easily.

Copyright (C) 2020-2025 Nicholas K. DIonysopoulos

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.

You should have received [a copy of the GNU General Public License](LICENSE.txt) along with this program.  If not, see <https://www.gnu.org/licenses/>.
