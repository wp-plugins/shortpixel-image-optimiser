=== ShortPixel Image Optimiser ===

Contributors: AlexSP
Tags: picture,  optimization, image editor, pngout, upload speed, shortpixel, compression, jpegmini, webp, lossless, cwebp, media, tinypng, jpegtran,image, image optimisation, shrink, picture, photo, optimize photos, compress, performance, tinypng, crunch, pngquant, attachment, optimize, pictures,fast, images, image files, image quality, lossy, upload, kraken, resize, seo, smushit, optipng, kraken image optimizer, ewww, photo optimization, gifsicle, image optimizer, images, krakenio, png, gmagick, image optimize
Requires at least: 3.0.0 or higher
Tested up to: 4.0
Stable tag: 1.4.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

The ShortPixel WordPress plugin optimizes images automaticaly using both lossy and lossless compression, thus improving your website performance.

== Description ==

ShortPixel is an image compression tool that helps improve your website performance. The plugin optimises images automatically using both lossy and lossless compression. Resulting, smaller, images are no different in quality from the original. 

ShortPixel uses powerful algorithms that enable your website to load faster, use less bandwidth and rank better in search.

**The ShortPixel package includes:**

* **Both lossy and lossless optimisation:** you can choose between the two types of compression. Lossy for photographs. Lossless for technical drawings, clip art and comics.
* **Up to 90% compression rate:** with lossy compression images that were 3MB can crunch to 307Kb, with no before/after differences.
* **Supported formats:** JPG, PNG, GIF (including animated): optimisation applies to JPG, PNG and static GIF. NEW UPDATE: we introduced optimisation for animated GIFs.
* **Backup and restore originals:** if you ever want to return to the original version, images are automatically stored in a backup folder on your hosting servers.
* **Batch image optimisation:**  Bulk Optimisation tool now available. Crunch your past image gallery, and downsize your website in minutes.

On the https://ShortPixel.com website, we offer free access to the ShrtPixel API which you can use for further image optimisation purposes.

== Installation ==

Let's get ShortPixel plugin running on your WordPress website:


1. Sign up using your email at https://shortpixel.com/wp-apikey
2. You will receive your personal API key in a confirmation email, to the address you provided.
3. Upload the ShortPixel plugin to the /wp-content/plugins/ directory
4. Use your unique API key to activate ShortPixel plugin in the 'Plugins' menu in WordPress.
5. Uploaded images can be automatically optimised in the Media Library.
6. Done!


== Frequently Asked Questions ==

= What happens to the existing images, when installing the ShortPixel plugin? = 

Just installing the plugin won’t start the optimisation process on existing images. To begin optimising the images previously loaded on your website, you should:
Go to Media Library, and select which of the existing images you want to optimise.
OR
Use the Bulk ShortPixel option, to automatically optimise all your previous library.

= What happens with my original images after they have been processed with ShortPixel? =

Your images are automatically stored in a backup folder, on your hosting server. After optimisation, if you want to switch back to a certain original image, hit **Restore backup** in the Media Library. If you are happy with the ShortPixel optimised images, you can deactivate saving the backups in the plugin Settings.

= Should I pick lossy or lossless optimisation? =

This depends on your compression needs. Lossy has a better compression rate than lossless compression. The resulting image is not 100% identical with the original. Works well for photos taken with your camera.

With lossless compression, the shrunk image will be identical with the original and smaller in size. Use this when you do not want to loose any of the original image's details. Works best for technical drawings, clip art and comics.

For more information about the difference read the <a href="http://en.wikipedia.org/wiki/Lossy_compression#Lossy_and_lossless_compression" target="_blank">Wiki article</a>.

= Why do I need an API key? =

ShortPixel uses automated processes to crunch images. The API integrates in the WordPress dashboard of your website and processes both old and new images automatically.
You can also use the API in your own applications, the <a href="https://shortpixel.com/api-docs">Documentation API</a> shows you how.

= Where do I get my API key? =

To get your API key, you must <a href="https://shortpixel.com/wp-apikey">Sign up to ShortPixel</a>. You will receive your personal API key in a confirmation email to the address you provided. Use your API key to activate ShortPixel plugin in the 'Plugins' menu in WordPress.

= Where do I use my API key? =

You use the API key in the ShortPixel plugin Settings, don’t forget to click Save Settings. The same API can be used on multiple websites/blogs. 

= What does bulk optimisation mean? =

The bulk option lets ShortPixel optimise all your images at once (not one by one). You can do this in the Media > Bulk ShortPixel section by clicking on the Compress all your images button.

= Are my images safe? =

Yes, privacy is guaranteed. The ShortPixel automated encryption process doesn't allow anyone to view your photos.

= What types of formats can be optimised? =

For now, ShortPixel supports JPEG and PNG. Thumbnails are also optimised. Additional formats are scheduled for optimisation in the future. 

= I’m stuck. What do I do? =

ShortPixel team is here to help. <a href="https://shortpixel.com/contact">Contact us</a>!

== Screenshots ==

1. Activate your API key in the plugin Settings. (Settings>ShortPixel)

2. Compress all your past images with one click. (Media>Bulk)

3. Your stats: number of processed files, saved space, average compression, saved bandwidth, remaining images. (Settings>ShortPixel)

4. Restore to original image. (Media>Library)

== Changelog ==

= 1.4.1 =

* optimize again overwrote the original image, fixed
* fixed restore errors
* changes to FAQ/Description texts

= 1.4.0 =

* Bulk image processing improved so it can optimize all the images in background while admin page is open
* small changes in readme.txt descrption

= 1.3.5 =

* fixed broken link in settings page
* updated FAQ
* description updated

= 1.3.2 =

* fixed missing action link @ Bulk Processing
* added more screenshots

= 1.3.1 =

* possible fix for API key validation failing
* added backup and restore for images that are processed with shortpixel
* optimize now feature on Media Library

= 1.0.6 =

* bulk processing runs in background now.

= 1.0.5 =

* extra check for the converted images to be safely copied from ShortPixel

= 1.0.4 =

* corrections and additions to readme.txt and wp-shortpixel.php

= 1.0.3 =

* minor bug fixes

= 1.0.2 =

* Updated Bulk editing to run in background
* Updated default options
* Added notifications on activation

= 1.0 =

* First working version

