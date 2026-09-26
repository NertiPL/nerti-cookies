<?php
/**
 * Plugin Name: Nerti Cookies — fikstura testowa
 * Description: TYLKO NA LOKALNEJ WITRYNIE TESTOWEJ. Wkleja na stronę /nc-test/ wszystkie rodzaje śledzenia, jakie wtyczka ma łapać. Kopiujesz do wp-content/mu-plugins, nigdy na stronę klienta.
 *
 * Każdy element jest tu z konkretnego powodu — to jest lista przypadków, na których blokada
 * potrafi się wyłożyć, a nie „przykładowa strona":
 *  - skrypt z src i inline (gtag, fbq, TikTok, Hotjar, Clarity) wklejone na sztywno w wp_head,
 *  - skrypt dołożony przez wp_enqueue_script (warstwa 1),
 *  - skrypt inline zamieniony na data: URI base64 (tak robi LiteSpeed),
 *  - skrypt opóźniony przez WP Rocket (type="rocketlazyloadscript", data-rocket-src)
 *    i przez LiteSpeed (type="litespeed/javascript", data-src),
 *  - piksel obrazkowy i piksel w <noscript>,
 *  - preconnect do domeny śledzącej,
 *  - iframe zwykły (YouTube, Mapy, Vimeo) i leniwy z data-src,
 *  - reCAPTCHA (marketingowe) i Turnstile (niezbędne, nie wolno go blokować),
 *  - ciasteczko ustawiane z PHP w nagłówku odpowiedzi (_ga), jak robi to wtyczka Meta z _fbp.
 *
 * Identyfikatory są fałszywe. Żądania i tak wychodzą do prawdziwych domen — o to chodzi,
 * bo test odmowy liczy właśnie żądania.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

function nc_fikstura_aktywna() {
	return ! is_admin() && isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( (string) $_SERVER['REQUEST_URI'], '/nc-test' ); // phpcs:ignore
}

// Ciasteczko analityczne z PHP, przed jakimkolwiek wyjściem — ten sam mechanizm co _fbp we wtyczce Meta.
add_action(
	'init',
	static function () {
		if ( nc_fikstura_aktywna() && ! headers_sent() ) {
			setcookie( '_ga', 'GA1.1.fikstura.' . time(), time() + 3600, '/' );
		}
	}
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( nc_fikstura_aktywna() ) {
			wp_enqueue_script( 'hotjar', 'https://static.hotjar.com/c/hotjar-0000000.js?sv=6', array(), null, true );
		}
	}
);

add_action(
	'wp_head',
	static function () {
		if ( ! nc_fikstura_aktywna() ) {
			return;
		}
		$b64 = base64_encode( "!function(){window.ttq=window.ttq||[];var s=document.createElement('script');s.src='https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=FIKSTURA';document.head.appendChild(s);}();" );
		?>
<link rel="preconnect" href="https://www.googletagmanager.com">
<link rel="dns-prefetch" href="//connect.facebook.net">
<script async src="https://www.googletagmanager.com/gtag/js?id=G-FIKSTURA00"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-FIKSTURA00');</script>
<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,"clarity","script","fikstura");</script>
<script src="data:text/javascript;base64,<?php echo esc_attr( $b64 ); ?>" defer></script>
<script type="rocketlazyloadscript" data-rocket-src="https://snap.licdn.com/li.lms-analytics/insight.min.js"></script>
<script type="litespeed/javascript" data-src="https://www.googleadservices.com/pagead/conversion_async.js"></script>
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
		<?php
	},
	5
);

// Stopka, nie the_content: szablony Oxygena potrafią nie wołać the_content wcale.
add_action(
	'wp_footer',
	static function () {
		if ( ! nc_fikstura_aktywna() ) {
			return;
		}
		echo '<main class="nc-fikstura">
<h2>YouTube</h2><iframe width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="Film testowy" allowfullscreen></iframe>
<h2>Mapa</h2><iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12" width="600" height="300" loading="lazy" title="Mapa testowa"></iframe>
<h2>Vimeo leniwe</h2><iframe data-src="https://player.vimeo.com/video/76979871" class="lazyload" width="640" height="360" title="Vimeo testowe"></iframe>
<h2>Piksel</h2><img src="https://www.facebook.com/tr?id=000&amp;ev=PageView&amp;noscript=1" width="1" height="1" alt="">
<noscript><img src="https://px.ads.linkedin.com/collect/?pid=000&amp;fmt=gif" width="1" height="1" alt=""></noscript>
<h2>Formularz</h2><div class="cf-turnstile" data-sitekey="1x00000000000000000000AA"></div></main>'; // phpcs:ignore
	},
	1
);
