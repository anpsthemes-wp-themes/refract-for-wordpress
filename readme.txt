=== Refract ===
Contributors: anpsthemes
Tags: social media, social sharing, schedule posts, open graph, content repurposing
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn each blog post into a post for every social network, in your voice. Approve every one, then let Refract schedule them.

== Description ==

You already write on your blog. With Refract, you don't have to write the same thing again for every network.

When you publish a post, Refract gets it as a note. It writes a version for each account you chose, in your workspace's voice, and crops the post's images for each network. Every version waits for your approval. You preview and approve the versions in the post editor, or in Refract. Nothing goes out until you approve it.

= Bring readers back from social =

Each version links back to your post, so your blog gets visits and your links get shared. Once a version is posted, the post editor shows the link to it and the clicks so far, for example "12 clicks from Instagram".

= From WordPress to Refract =

* Send posts when they're published, or only when you choose, from the editor.
* Choose the post types, the categories and the accounts.
* See each version in the post sidebar: its text, its image in the network's shape and its status.
* Approve each version, or approve all and add them to the queue. Or open one in Refract to edit it.
* Works with the block editor and the Classic Editor plugin.

= From Refract to WordPress =

Your site becomes an account in Refract. From Refract you can publish or schedule blog posts to it, or send them as drafts. You choose which user the posts are published as, the default category, and whether Refract can publish or only send drafts. You can turn this off at any time.

= Link previews =

The plugin can add social preview tags (Open Graph and X card), so your links show a title and an image on every network. If another plugin already adds these tags, the plugin leaves them to it.

= Networks =

<!-- networks -->
The networks you can connect today are listed on refract.social. This list is filled in from Refract when the plugin is released.
<!-- /networks -->

= What you need =

* A Refract account. You can start with a free trial at [refract.social](https://refract.social), no card needed.
* AI credits for the versions. Each version costs 1 AI credit, and your plan includes credits each month. With your own AI key in Refract, versions cost no credits. You can also send the same post, with the link, to every account for free.
* WordPress 6.8 or later and PHP 8.1 or later. Multisite works: each site connects separately.

= Source code =

The JavaScript in `build/` is made with [@wordpress/scripts](https://www.npmjs.com/package/@wordpress/scripts). Its unminified source is in the plugin's `src/` folder, and the build setup is in `package.json` and `webpack.config.js`. To build it yourself, run `npm install` and then `npm run build` in the plugin's folder. The source code is also on GitHub: [anpsthemes-wp-themes/refract-for-wordpress](https://github.com/anpsthemes-wp-themes/refract-for-wordpress).

== Installation ==

1. In WordPress, go to Plugins › Add New, search for "Refract" and click Install, then Activate. Or upload the plugin's folder to `/wp-content/plugins/` and activate it on the Plugins screen.
2. Go to Refract › Settings and click "Connect to Refract". Sign in to Refract, or start a free trial, and allow the connection.
3. Choose the workspace, the accounts that get a version of each post, and when to send posts to Refract. Click Save.

If the redirect back to your site doesn't work, for example on a local site or a site behind a login, open "Connect with a token instead". Create a token in Refract under Settings › API and agents, and paste it there.

== Frequently Asked Questions ==

= Do I need a Refract account? =

Yes. The plugin connects your site to Refract, which writes the versions and schedules the posts. You can start with a free trial.

= Does anything go out without my approval? =

No. Every version waits for your approval. Approve it in the post editor or in Refract, and Refract schedules it in your queue.

= What does it cost? =

The plugin is free. Refract is a paid service with a free trial. Each version costs 1 AI credit, or none with your own AI key. "The same post for every account, with the link" is always free. If you run out of credits, the post sidebar tells you, and you can send the same post to every account instead.

= What is sent to Refract, and when? =

Nothing, until you click Connect. The External services section below lists what's sent and when.

= Can I change a version before it's posted? =

Yes. Click "Edit in Refract" on the version to open it in Refract.

= What happens when I update a post that was already sent? =

By default, Refract updates the note when the title, excerpt or text changes. You can choose "Leave it" in the settings instead.

= Can Refract publish to my site? =

Only if you turn on "Let Refract publish to this site" in the settings. You can limit it to sending drafts. Posts that come from Refract are never sent back to Refract.

= Does it work with multisite? =

Yes. Network activation works, and each site connects to Refract separately.

= What happens when I deactivate or delete the plugin? =

Deactivating stops the plugin's scheduled tasks and keeps the connection. Deleting the plugin removes its settings and post data, and asks Refract to end the connection.

== Screenshots ==

1. The setup after connecting: the accounts that get a version of each post, grouped by network, and when to send posts.
2. The settings page.
3. The Refract panel in the post editor, with a version for each account.
4. The Activity page.

== External services ==

This plugin connects your site to Refract (https://refract.social), a service that writes social posts from your blog posts and schedules them. You need a Refract account to use it. Nothing is sent to Refract until you click "Connect to Refract".

What's sent, and when:

* When you connect: your site's name and address, the versions of WordPress and of the plugin, the site's language and time zone, and a signing secret the plugin makes, so Refract can sign what it sends to your site.
* When Refract asks what your site looks like (when you connect, and when someone writes a post for your site in Refract; Refract keeps the answer for 10 minutes): your site's name and address, the versions of WordPress and of the plugin, the site's language and time zone, its categories and up to 200 tags, the default category, and the display name of the person Refract publishes as.
* When a post is sent to Refract, after you publish it or click "Send to Refract": the post's title, excerpt, text, link and images (with their alt text), and the accounts chosen for it. Updates to the post are sent the same way, unless you turn that off.
* When the post sidebar loads its versions: the post's ID in Refract. When you approve versions, which ones you approved.
* When you disconnect or delete the plugin: a request to end the connection.

Refract also sends requests to your site: it downloads the post's images from their links, sends updates about your versions, and, if you allow it, publishes or schedules posts on your site.

Refract's terms of service: https://refract.social/terms
Refract's privacy policy: https://refract.social/privacy

== Changelog ==

= 1.2.0 =
* A clearer Settings page: the connection at the top, a section for each part (Sending, Accounts, Writing and images, Publishing from Refract, Link previews) with a summary of what's chosen, and links to jump between them.
* Choices are cards that say what each one does, and which ones use AI credits or are free.
* Accounts show as cards by network, with how many are chosen and Select all or Select none. Pick categories by searching for them.
* Save changes stays in view while you have unsaved changes, with Discard changes. Leaving the page asks first.
* Settings and Activity are tabs at the top of both pages.

= 1.1.0 =
* Refract can remove a post it published on your site: when you remove the post in Refract, it moves to the trash here.

= 1.0.0 =
* First release: connect your site, send posts to Refract, preview and approve the versions in the editor, publish from Refract to your site, social preview tags and the Activity log.

== Upgrade Notice ==

= 1.2.0 =
A clearer Settings page.

= 1.1.0 =
Lets you remove posts from your site in Refract.

= 1.0.0 =
First release.
