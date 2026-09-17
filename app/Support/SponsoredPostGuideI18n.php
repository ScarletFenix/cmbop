<?php

namespace App\Support;

/**
 * DE/FR/NL bodies for the sponsored-post-guide pillar.
 */
class SponsoredPostGuideI18n
{
    /**
     * @return array<string, array{title: string, slug: string, excerpt: string, content: string, meta_title: string, meta_description: string}>
     */
    public static function all(): array
    {
        return [
            'de' => [
                'title' => 'Gesponserte Beiträge: Was sie sind, wie sie funktionieren, was Advertiser wissen sollten',
                'slug' => 'gesponserte-beitraege-leitfaden',
                'excerpt' => 'Gesponserte Beiträge sind bezahlte Platzierungen. Unterschied zu Gastbeiträgen, Publisher-Wahl, Kennzeichnung bezahlter Links.',
                'meta_title' => 'Gesponserte Beiträge: Was Advertiser wissen sollten',
                'meta_description' => 'Gesponserte Beiträge erklärt: Unterschied zu Gastbeiträgen, Publisher wählen, und wie bezahlte Links gekennzeichnet werden sollten.',
                'content' => self::de(),
            ],
            'fr' => [
                'title' => 'Articles sponsorisés: ce qu’ils sont, comment ça marche, ce que les annonceurs doivent savoir',
                'slug' => 'articles-sponsorises-guide',
                'excerpt' => 'Un article sponsorisé est un placement payant. Différence avec le guest post, choix de l’éditeur, marquage des liens payants.',
                'meta_title' => 'Articles sponsorisés: ce que les annonceurs doivent savoir',
                'meta_description' => 'Articles sponsorisés: différence avec le guest post, choix des éditeurs, et comment les liens payants doivent être marqués.',
                'content' => self::fr(),
            ],
            'nl' => [
                'title' => 'Gesponsorde posts: wat ze zijn, hoe ze werken, wat adverteerders moeten weten',
                'slug' => 'gesponsorde-posts-gids',
                'excerpt' => 'Een gesponsorde post is een betaalde plaatsing. Verschil met gastposts, publisher kiezen, labeling van betaalde links.',
                'meta_title' => 'Gesponsorde artikelen: wat adverteerders moeten weten',
                'meta_description' => 'Gesponsorde posts uitgelegd: verschil met gastposts, publishers kiezen, en hoe betaalde links gelabeld moeten worden.',
                'content' => self::nl(),
            ],
        ];
    }

    private static function de(): string
    {
        $backlinks = '/blog/how-to-get-backlinks';
        $guest = '/blog/guest-posting-guide';
        $linkGuide = '/blog/link-building-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $brief = '/blog/guest-post-brief-anchors-urls-images-sensitive-topics';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $price = '/blog/how-to-price-your-site-and-sensitive-niches';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $removed = '/blog/what-happens-if-a-live-link-is-removed';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $img = BlogInlineImages::publicUrl(SponsoredPostGuideBlogPost::IMAGE_COMPARE);

        return <<<HTML
<p>Ein gesponserter Beitrag ist ein Artikel (oder ein Abschnitt), den ein Publisher schaltet, weil jemand dafür bezahlt, getauscht oder anderweitig vergütet hat. Das ist Werbung im redaktionellen Gewand. Erlaubt. So zu tun, als wäre es eine unbezahlte Empfehlung, nicht.</p>
<p>Unbezahlter Contributor-Workflow: <a href="{$guest}">Gastbeiträge</a>. Taktik-Mix: <a href="{$linkGuide}">Linkbuilding</a>.</p>

<h2>Was ist ein gesponserter Beitrag?</h2>
<p>Native Advertising auf der Site des Publishers. Andere Namen — Advertorial, Paid Post, Partner Content — beschreiben denselben kommerziellen Fakt. Auch Produkt, Affiliate-Zwang oder Rabatt gegen eine Live-URL zählen als Gegenleistung. Würde der Artikel ohne den Deal nicht existieren, behandeln Sie ihn als gesponsert.</p>

<h2>Sponsored vs. Gastbeitrag vs. Editorial</h2>
<figure>
<img src="{$img}" alt="Vergleich: redaktioneller Artikel, Gastbeitrag, gesponserter Beitrag — Auftraggeber, Zahlung, Disclosure, Link-Kennzeichnung" loading="lazy" width="1200" height="675">
<figcaption>Bezahlte Platzierungen sind erlaubt. Ein unqualifizierter Ranking-Link als Produkt ist das Problem in Googles Spam-Richtlinien.</figcaption>
</figure>
<p>Ein „Gastbeitrag“-Invoice für einen Exact-Match-Dofollow ist eine gesponserte Platzierung mit freundlicherem Namen. In Ihren Reports die Labels behalten.</p>

<h2>Ablauf und warum Unternehmen das nutzen</h2>
<p>Landingpage und Job festlegen (Traffic, Zitation oder beides), Publisher kurzlisten, Preis, Disclosure, Attribut, Laufzeit schriftlich, schreiben/lektorieren, live, später rechecken — <a href="{$live}">nach dem Live-Link</a>, <a href="{$removed}">wenn ein Link entfernt wird</a>.</p>
<p>Auf einem Marketplace wie <a href="{$catalog}">SEOLinkBuildings</a> stehen Regeln oft auf dem Listing. Das erleichtert den Vergleich. Es macht nicht jedes Listing zum guten Kauf. <a href="{$how}">So funktioniert es</a>, <a href="{$buyGuide}">Kaufleitfaden</a>.</p>
<p>Ehrliche Gründe: Reichweite, eine gekennzeichnete Zitation, Kontrolle über die Story. Schwacher Grund: „Ranking-Saft“ in Bulk ohne Qualifizierung.</p>

<h2>Publisher wählen</h2>
<p>Relevanz, Publikum, Traffic der Sektion (nicht nur der Domain), Content-Qualität, schriftliche Constraints, Preis ohne globale Fantasie-Listen. Filter: <a href="{$chooseSite}">Publisher-Site wählen</a>. Brief: <a href="{$brief}">Anker, URLs, Bilder</a>. Publisher-Preise: <a href="{$price}">Site bepreisen</a>.</p>

<h2>Link-Attribute und Google</h2>
<p>Spam-Richtlinien: Kauf/Verkauf von Links, <em>die Ranking-Credit weitergeben</em>, zur Manipulation. Werbung ist normal, wenn qualifiziert. <code>rel="sponsored"</code> ist das dokumentierte Attribut für Paid Placements (2019, neben <code>ugc</code>). <code>nofollow</code> bleibt gültig; seit 2019 Hinweise, keine harte Ausschlussliste. Details: <a href="{$dofollow}">Dofollow, Nofollow, Ankertexte</a>.</p>
<p>Publisher nicht bitten, <code>sponsored</code> zu streichen, „damit es mehr zählt“. Beschaffung: <a href="{$backlinks}">Backlinks bekommen</a>.</p>

<h2>Was Advertiser fragen, was Publisher offenlegen</h2>
<p>Welche URL, wer schreibt, welches <code>rel</code>, welches Label für Leser, indexierbar, Laufzeit, wie viele kommerzielle Links, Freigabe vor Live, sensible Themen. Leser müssen den Deal verstehen. In den USA erwarten die FTC-Endorsement-Guides eine klare Disclosure bei materieller Verbindung. HTML-Attribute und menschliche Disclosure sind zwei Jobs.</p>

<h2>Checkliste</h2>
<ul>
<li>Platzierung für Leser als bezahlt erkennbar</li>
<li>Kommerzielle Links <code>sponsored</code> und/oder <code>nofollow</code></li>
<li>Publikum überlappt mit Käufern, nicht nur mit Keywords</li>
<li>Keine anonymen „DA“-Pakete</li>
</ul>

<h2>Häufig gestellte Fragen</h2>
<h3>Erlaubt Google gesponserte Beiträge?</h3>
<p>Werbung ist Teil des Webs. Das Problem ist der unqualifizierte Ranking-Link als Ware.</p>
<h3>Ist das dasselbe wie ein Gastbeitrag?</h3>
<p>Nein. Manche Vendoren nutzen „Guest Post“ für beides. Vertrag und <code>rel</code> sollten die Wahrheit sagen.</p>
<h3>Wird ein Sponsored Link das Ranking verbessern?</h3>
<p>Niemand kann das versprechen. Ein relevanter Artikel kann trotzdem Leser schicken.</p>
<h3>Jeder bezahlte Link nofollow?</h3>
<p>Das dokumentierte Qualifier-Attribut ist <code>sponsored</code>. <code>nofollow</code> ist ebenfalls akzeptabel.</p>

<h2>Quellen</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Google Search Central — Spam-Richtlinien</a></li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Evolving nofollow (2019)</a></li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Qualifying links (2021)</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Crawlable links</a></li>
<li><a href="https://www.ftc.gov/business-guidance/resources/ftcs-endorsement-guides-what-people-are-asking">FTC Endorsement Guides (US)</a></li>
</ul>
HTML;
    }

    private static function fr(): string
    {
        $backlinks = '/blog/how-to-get-backlinks';
        $guest = '/blog/guest-posting-guide';
        $linkGuide = '/blog/link-building-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $brief = '/blog/guest-post-brief-anchors-urls-images-sensitive-topics';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $price = '/blog/how-to-price-your-site-and-sensitive-niches';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $removed = '/blog/what-happens-if-a-live-link-is-removed';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $img = BlogInlineImages::publicUrl(SponsoredPostGuideBlogPost::IMAGE_COMPARE);

        return <<<HTML
<p>Un article sponsorisé est un texte (ou une section) qu’un éditeur publie parce que quelqu’un a payé, troqué ou autrement compensé le placement. C’est de la publicité qui ressemble à de l’éditorial. Autorisé. Faire semblant que c’est une recommandation gratuite: non.</p>
<p>Workflow contributeur: <a href="{$guest}">guest posting</a>. Mix tactique: <a href="{$linkGuide}">netlinking</a>.</p>

<h2>Qu’est-ce qu’un article sponsorisé?</h2>
<p>Publicité native. Advertorial, paid post, partner content: même fait commercial. Produit offert, affiliation qui exige un lien, remise contre une URL live: toujours du payant. Si l’article n’existerait pas sans l’accord, traitez-le comme sponsorisé.</p>

<h2>Sponsorisé vs guest post vs éditorial</h2>
<figure>
<img src="{$img}" alt="Comparaison article éditorial, guest post et article sponsorisé: qui lance, paiement, divulgation, marquage du lien" loading="lazy" width="1200" height="675">
<figcaption>Les placements payants sont autorisés. Le lien de ranking non qualifié vendu comme produit est le problème décrit par Google.</figcaption>
</figure>

<h2>Déroulement</h2>
<p>URL cible et objectif, shortlist, termes écrits, rédaction, mise en ligne, recontrôle — <a href="{$live}">après le lien live</a>, <a href="{$removed}">si le lien disparaît</a>. Un catalogue comme <a href="{$catalog}">SEOLinkBuildings</a> compare des règles, ce n’est pas un tampon qualité. <a href="{$how}">Comment ça marche</a>, <a href="{$buyGuide}">guide acheteur</a>.</p>
<p>Raisons honnêtes: audience, citation marquée, contrôle du récit. Raison faible: acheter du « jus » en volume sans qualification.</p>

<h2>Choisir un éditeur</h2>
<p>Pertinence, audience, trafic de la rubrique, qualité des textes, contraintes écrites, prix sans moyenne mondiale inventée. <a href="{$chooseSite}">Choisir un site</a>, <a href="{$brief}">brief</a>, <a href="{$price}">tarifer son site</a>.</p>

<h2>Attributs et Google</h2>
<p>Acheter/vendre des liens qui transmettent du crédit de ranking pour manipuler: spam de liens. La pub est normale si elle est qualifiée. <code>rel="sponsored"</code> est l’attribut documenté (2019). <code>nofollow</code> reste valable; ce sont des indices. <a href="{$dofollow}">Dofollow, nofollow, ancres</a>. Ne demandez pas de retirer <code>sponsored</code>. Acquisition: <a href="{$backlinks}">obtenir des backlinks</a>.</p>

<h2>Questions à poser, ce qu’il faut divulguer</h2>
<p>URL, rédacteur, <code>rel</code>, label lecteur, indexation, durée, nombre de liens commerciaux, validation avant live, sujets sensibles. Les lecteurs doivent comprendre l’accord. Aux États-Unis, les guides FTC sur les endorsements attendent une divulgation claire. Attributs HTML et mention humaine: deux tâches.</p>

<h2>Checklist</h2>
<ul>
<li>Le lecteur voit que c’est payé</li>
<li>Liens commerciaux <code>sponsored</code> et/ou <code>nofollow</code></li>
<li>L’audience recoupe vos acheteurs</li>
<li>Pas de packs « DA » anonymes</li>
</ul>

<h2>Questions fréquentes</h2>
<h3>Google autorise-t-il les articles sponsorisés?</h3>
<p>La publicité fait partie du web. Le problème est le lien de ranking non qualifié.</p>
<h3>Est-ce un guest post?</h3>
<p>Non. Certains vendeurs utilisent le même mot. Le contrat et le <code>rel</code> doivent dire la vérité.</p>
<h3>Le lien va-t-il améliorer mon ranking?</h3>
<p>Personne ne peut le promettre. Un article pertinent peut quand même envoyer des lecteurs.</p>
<h3>Tous les liens payants en nofollow?</h3>
<p>Le qualificateur documenté est <code>sponsored</code>. <code>nofollow</code> est aussi acceptable.</p>

<h2>Sources</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Politiques anti-spam Google</a></li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Evolving nofollow (2019)</a></li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Qualifying links (2021)</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Liens crawlables</a></li>
<li><a href="https://www.ftc.gov/business-guidance/resources/ftcs-endorsement-guides-what-people-are-asking">Guides FTC (US)</a></li>
</ul>
HTML;
    }

    private static function nl(): string
    {
        $backlinks = '/blog/how-to-get-backlinks';
        $guest = '/blog/guest-posting-guide';
        $linkGuide = '/blog/link-building-guide';
        $chooseSite = '/blog/how-to-choose-a-publisher-site-dr-da-traffic-niche';
        $buyGuide = '/blog/how-to-buy-guest-posts-on-seolinkbuildings-advertiser-guide';
        $brief = '/blog/guest-post-brief-anchors-urls-images-sensitive-topics';
        $dofollow = '/blog/dofollow-nofollow-and-anchor-text-for-marketplace-links';
        $price = '/blog/how-to-price-your-site-and-sensitive-niches';
        $live = '/blog/what-to-check-after-the-live-link-indexation-attributes-rankings';
        $removed = '/blog/what-happens-if-a-live-link-is-removed';
        $catalog = '/marketplace';
        $how = '/how-it-works';
        $img = BlogInlineImages::publicUrl(SponsoredPostGuideBlogPost::IMAGE_COMPARE);

        return <<<HTML
<p>Een gesponsorde post is een artikel (of een deel ervan) dat een publisher plaatst omdat iemand ervoor betaalde, ruilde of anderszins vergoedde. Reclame in redactionele vorm. Toegestaan. Doen alsof het een onbetaalde aanbeveling is: niet.</p>
<p>Onbetaalde contributor-flow: <a href="{$guest}">gastbloggen</a>. Tactiekenmix: <a href="{$linkGuide}">linkbuilding</a>.</p>

<h2>Wat is een gesponsorde post?</h2>
<p>Native advertising. Advertorial, paid post, partner content: hetzelfde commerciële feit. Product, affiliate-eis of korting tegen een live-URL telt ook. Zou het stuk zonder de deal niet bestaan, behandel het als sponsored.</p>

<h2>Sponsored vs gastpost vs redactioneel</h2>
<figure>
<img src="{$img}" alt="Vergelijking redactioneel artikel, gastpost en gesponsorde post: wie start, betaling, disclosure, linklabel" loading="lazy" width="1200" height="675">
<figcaption>Betaalde plaatsingen mogen. Een ongekwalificeerde rankinglink als product is het probleem in Google’s spambeleid.</figcaption>
</figure>

<h2>Werkwijze</h2>
<p>Doel-URL en job, shortlist, voorwaarden op papier, schrijven, live, later rechecken — <a href="{$live}">na de live link</a>, <a href="{$removed}">als een link verdwijnt</a>. Een catalogus zoals <a href="{$catalog}">SEOLinkBuildings</a> vergelijkt regels, het is geen keurmerk. <a href="{$how}">Hoe het werkt</a>, <a href="{$buyGuide}">koopgids</a>.</p>
<p>Eerlijke redenen: bereik, een gelabelde citatie, controle over het verhaal. Zwakke reden: ranking-sap in bulk zonder kwalificatie.</p>

<h2>Een publisher kiezen</h2>
<p>Relevantie, publiek, verkeer van de rubriek, contentkwaliteit, schriftelijke constraints, prijs zonder verzonnen wereldgemiddelde. <a href="{$chooseSite}">Site kiezen</a>, <a href="{$brief}">brief</a>, <a href="{$price}">je site prijzen</a>.</p>

<h2>Attributen en Google</h2>
<p>Kopen/verkopen van links die rankingcredit doorgeven om te manipuleren: linkspam. Reclame is normaal als die is gekwalificeerd. <code>rel="sponsored"</code> is het gedocumenteerde attribuut (2019). <code>nofollow</code> blijft geldig; het zijn hints. <a href="{$dofollow}">Dofollow, nofollow, ankers</a>. Vraag niet om <code>sponsored</code> te strippen. Acquisitie: <a href="{$backlinks}">backlinks krijgen</a>.</p>

<h2>Wat je vraagt, wat je openbaart</h2>
<p>Welke URL, wie schrijft, welk <code>rel</code>, welk lezerslabel, indexeerbaar, looptijd, aantal commerciële links, goedkeuring voor live, gevoelige topics. Lezers moeten de deal begrijpen. In de VS verwachten de FTC-endorsementguides een duidelijke disclosure. HTML-attributen en menselijke disclosure zijn twee taken.</p>

<h2>Checklist</h2>
<ul>
<li>De lezer ziet dat het betaald is</li>
<li>Commerciële links <code>sponsored</code> en/of <code>nofollow</code></li>
<li>Publiek overlapt met kopers</li>
<li>Geen anonieme „DA”-pakketten</li>
</ul>

<h2>Veelgestelde vragen</h2>
<h3>Staan gesponsorde posts Google toe?</h3>
<p>Reclame hoort bij het web. Het probleem is de ongekwalificeerde rankinglink.</p>
<h3>Is het hetzelfde als een gastpost?</h3>
<p>Nee. Sommige verkopers gebruiken hetzelfde woord. Contract en <code>rel</code> moeten de waarheid zeggen.</p>
<h3>Gaat de link mijn ranking verbeteren?</h3>
<p>Niemand kan dat beloven. Een relevant artikel kan wél lezers sturen.</p>
<h3>Elke betaalde link nofollow?</h3>
<p>De gedocumenteerde qualifier is <code>sponsored</code>. <code>nofollow</code> mag ook.</p>

<h2>Bronnen</h2>
<ul>
<li><a href="https://developers.google.com/search/docs/essentials/spam-policies">Google-spambeleid</a></li>
<li><a href="https://developers.google.com/search/blog/2019/09/evolving-nofollow-new-ways-to-identify">Evolving nofollow (2019)</a></li>
<li><a href="https://developers.google.com/search/blog/2021/07/link-tagging-and-link-spam-update">Qualifying links (2021)</a></li>
<li><a href="https://developers.google.com/search/docs/crawling-indexing/links-crawlable">Crawlable links</a></li>
<li><a href="https://www.ftc.gov/business-guidance/resources/ftcs-endorsement-guides-what-people-are-asking">FTC-gidsen (VS)</a></li>
</ul>
HTML;
    }
}
