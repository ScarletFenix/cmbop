<?php

namespace Database\Seeders;

use App\Models\ContentSubmission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Local dummy articles across catalog niches so /advertiser/content-library
 * is not empty. Idempotent per user + original_filename.
 */
class ContentLibraryNicheDemoSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::query()->where('name', 'advertiser')->first();
        if ($role === null) {
            $this->command?->warn('No advertiser role — run RolesTableSeeder first.');

            return;
        }

        $advertisers = User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
            ->get();

        if ($advertisers->isEmpty()) {
            $this->command?->warn('No advertiser users — log in once, then re-run this seeder.');

            return;
        }

        foreach ($advertisers as $user) {
            foreach ($this->articles() as $article) {
                $this->upsertArticle($user, $article);
            }
        }

        $this->command?->info('Seeded '.$advertisers->count().' advertiser librar'.($advertisers->count() === 1 ? 'y' : 'ies').' with '.count($this->articles()).' niche articles each.');
    }

    /**
     * @param  array{slug: string, niche: string, title: string, country: string, language: string, body: list<string>}  $article
     */
    protected function upsertArticle(User $user, array $article): void
    {
        $filename = 'demo-niche-'.$article['slug'].'.docx';
        $relative = 'content-uploads/'.$user->id.'/demo-niche-'.$article['slug'].'.docx';
        $html = $this->toHtml($article['title'], $article['body']);
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        $words = str_word_count($text);

        Storage::disk('local')->put($relative, $this->minimalDocx($text));

        ContentSubmission::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'original_filename' => $filename,
            ],
            [
                'site_id' => null,
                'copy_index' => 0,
                'title' => $article['title'],
                'country' => $article['country'],
                'language' => $article['language'],
                'disk' => 'local',
                'path' => $relative,
                'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'extension' => 'docx',
                'size_bytes' => Storage::disk('local')->size($relative) ?: 400,
                'extracted_text' => $text,
                'preview_html' => $html,
                'word_count' => $words,
                'uniqueness_score' => 88,
                'quality_score' => 84,
                'evaluation_status' => 'approved',
                'evaluated_at' => now(),
                'moderation_status' => ContentSubmission::STATUS_APPROVED,
                'anchor_text' => null,
                'target_url' => null,
                'image_rights' => ContentSubmission::IMAGE_RIGHTS_NONE,
                'publication_mode' => ContentSubmission::MODE_IMMEDIATE,
                'timezone' => 'UTC',
                'wizard_step' => 5,
                'draft_payload' => ['demo_niche' => $article['niche']],
                'order_id' => null,
                'order_item_id' => null,
                'archived_at' => null,
                'expires_at' => now()->addMonths(6),
            ]
        );
    }

    /**
     * @return list<array{slug: string, niche: string, title: string, country: string, language: string, body: list<string>}>
     */
    protected function articles(): array
    {
        return [
            [
                'slug' => 'saas-b2b',
                'niche' => 'SaaS & B2B Software',
                'title' => 'How B2B teams pick a project workspace that actually gets used',
                'country' => 'us',
                'language' => 'en',
                'body' => [
                    'Buying another workspace tool is easy. Getting the whole company to open it on Monday is the hard part. Teams that succeed treat rollout as a product launch, not an IT ticket.',
                    'Start with one painful weekly ritual: status updates, handoffs, or client reporting. Replace that ritual first. If the new tool does not save thirty minutes in week one, people will quietly go back to email.',
                    'Ask two champions in sales and delivery to write the first templates. Shared templates beat a blank screen. Then publish a one-page “how we work here” note with three screenshots and a 10-minute walkthrough.',
                    'Measure adoption with real work, not login counts. How many projects have a living brief? How many updates land before the Friday standup? Those numbers tell you whether the workspace is a habit or a graveyard of unused licenses.',
                ],
            ],
            [
                'slug' => 'health-wellness',
                'niche' => 'Health & Wellness',
                'title' => 'A practical week of recovery habits that do not require a retreat',
                'country' => 'gb',
                'language' => 'en',
                'body' => [
                    'Recovery is not a spa day you schedule once a quarter. It is a set of small defaults that keep sleep, food, and movement from collapsing during a busy week.',
                    'Protect a consistent wind-down: screens dim one hour before bed, caffeine cut after early afternoon, and a short walk after the last meeting. None of this is glamorous. It is how energy stops leaking.',
                    'On training days, pair effort with a protein-rich meal and water you can actually see — a bottle on the desk beats a vague “hydrate more” goal. On rest days, keep moving lightly so stiffness does not become an excuse to skip the next session.',
                    'If you only change one thing, pick sleep timing. A stable wake time does more for mood and focus than a new supplement stack. Treat recovery like calendar work, not willpower.',
                ],
            ],
            [
                'slug' => 'travel-tourism',
                'niche' => 'Travel & Tourism',
                'title' => 'Planning a shoulder-season city break without the tourist crush',
                'country' => 'de',
                'language' => 'en',
                'body' => [
                    'Shoulder season is when cities feel like themselves again. Hotels still have rooms, museums have air, and you can walk a river path without a tour-group bottleneck.',
                    'Book the one timed ticket you care about first — a palace, a gallery, a hillside funicular — then leave the rest loose. A good neighborhood base with bakeries and a tram stop beats a “central” hotel on a six-lane road.',
                    'Eat where office workers queue at lunch, not where menus are printed in five languages. One sit-down lunch and one market dinner is usually enough to remember the place.',
                    'Pack for weather swings and closed Mondays. The best travel days are the ones you did not over-schedule: a long walk, one museum, and time to get lost on purpose.',
                ],
            ],
            [
                'slug' => 'ecommerce-retail',
                'niche' => 'E-commerce & Retail',
                'title' => 'Product pages that answer the three doubts that kill checkout',
                'country' => 'us',
                'language' => 'en',
                'body' => [
                    'Most abandoned carts are not a payment-bug story. Shoppers still have three open questions: will this fit my use, what happens if it is wrong, and when will it actually arrive.',
                    'Lead the page with the job the product does, not a slogan. A 40-word use case plus a size or spec table beats a hero film that never mentions dimensions.',
                    'Put returns, shipping windows, and who pays for a swap above the fold on mobile. Trust badges do nothing if the policy is buried in a footer PDF.',
                    'Use photos of the product in a real room or hand, then one honest close-up of materials. Customer photos belong next to the add-to-cart button, not in a separate tab nobody opens.',
                ],
            ],
            [
                'slug' => 'finance-sme',
                'niche' => 'Finance for SMEs',
                'title' => 'A simple cash-flow rhythm for a ten-person company',
                'country' => 'ie',
                'language' => 'en',
                'body' => [
                    'Small companies rarely fail from a lack of invoices. They fail when cash and calendar drift apart: tax, VAT, and a slow payer all land in the same fortnight.',
                    'Run a Monday money meeting that lasts fifteen minutes. Look at bank balance, invoices due this week, and bills you cannot move. Write the gap on one line. That line is the week’s priority.',
                    'Offer two payment terms you actually enforce. Early-pay discounts only work if someone follows up on day 31. Late fees only work if they are on the invoice before the work starts.',
                    'Keep a six-week cash sketch, not a twelve-month fantasy model. Update it when a deal slips. The sketch is there to stop surprise, not to impress a board you do not have yet.',
                ],
            ],
            [
                'slug' => 'cybersecurity',
                'niche' => 'Cybersecurity & Data Privacy',
                'title' => 'Password hygiene a non-technical team will actually follow',
                'country' => 'us',
                'language' => 'en',
                'body' => [
                    'Security advice fails when it sounds like a lecture. People reuse passwords because remembering 40 unique strings is not their job. Give them a manager and a rule they can finish in one afternoon.',
                    'Roll out a password manager with a shared vault for the three tools everyone touches. Turn on phishing-resistant MFA where the vendor supports it. Then retire SMS codes for admin accounts.',
                    'Write a one-page “if you click a weird link” card: disconnect, tell the named owner, do not reset passwords from the email. Rehearse it once. Panic is slower than a printed step list.',
                    'Review access when someone changes role. Most leaks are leftover accounts, not Hollywood hacks. Offboarding is a security control, not just an HR checklist.',
                ],
            ],
            [
                'slug' => 'home-garden',
                'niche' => 'Home & Garden',
                'title' => 'A small balcony garden that survives a first summer',
                'country' => 'nl',
                'language' => 'en',
                'body' => [
                    'A balcony is a harsh farm: wind, reflected heat, and a watering schedule that collapses on holiday weeks. Pick plants that forgive you, then make watering boring and automatic.',
                    'Use the deepest pots you can lift. Herbs and salad leaves in shallow trays dry out by Thursday. Mix a water-holding compost and add a saucer you empty after storms so roots do not sit in a swamp.',
                    'Put Mediterranean herbs in the sunniest corner and leafy greens where they get afternoon shade. One automatic drip bottle or a neighbour with a key is worth more than a complicated irrigation video.',
                    'Harvest often. Basil and salad only stay tender if you keep cutting. A balcony garden that feeds two dinners a week is a success. Anything more is a bonus.',
                ],
            ],
            [
                'slug' => 'education-elearning',
                'niche' => 'Education & E-learning',
                'title' => 'Designing a short course people finish on their phone',
                'country' => 'us',
                'language' => 'en',
                'body' => [
                    'Completion dies when a lesson needs a desk, a headset, and forty quiet minutes. If your learners are on a commute, build for twelve-minute blocks and a single obvious next tap.',
                    'Open each module with the decision they will make afterwards, not a history of the topic. One example, one practice prompt, one checklist they can screenshot.',
                    'Keep video optional. A transcript and three slides travel better through bad signal. If you must film, talk to one person and show the screen they will use.',
                    'Send a mid-course nudge that names the exact lesson they left, not a generic “come back”. People finish courses that remember where they were.',
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $paragraphs
     */
    protected function toHtml(string $title, array $paragraphs): string
    {
        $html = '<h2>'.e($title).'</h2>';
        foreach ($paragraphs as $paragraph) {
            $html .= '<p>'.e($paragraph).'</p>';
        }

        return $html;
    }

    protected function minimalDocx(string $text): string
    {
        $safe = htmlspecialchars(Str::limit($text, 2000, ''), ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:r><w:t>'.$safe.'</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
