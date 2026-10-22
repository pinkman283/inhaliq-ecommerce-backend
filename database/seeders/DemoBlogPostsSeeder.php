<?php

namespace Database\Seeders;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\BlogComment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoBlogPostsSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::whereIn('role', ['super_admin', 'admin'])->first() ?? User::first();

        // 1. Categories
        $categoriesData = [
            [
                'name' => 'Device Guides',
                'slug' => 'device-guides',
                'description' => 'Comprehensive walkthroughs, charging advice, and setup guides for pod systems and mods.',
                'status' => 'active',
            ],
            [
                'name' => 'Tech & Engineering',
                'slug' => 'tech-engineering',
                'description' => 'In-depth looks at coil materials, airflow dynamics, and chipset safety architectures.',
                'status' => 'active',
            ],
            [
                'name' => 'Authenticity & Safety',
                'slug' => 'authenticity-safety',
                'description' => 'Best practices for hardware verification, battery care, and avoiding counterfeit products.',
                'status' => 'active',
            ],
        ];

        $categories = [];
        foreach ($categoriesData as $catData) {
            $categories[$catData['slug']] = BlogCategory::updateOrCreate(
                ['slug' => $catData['slug']],
                $catData
            );
        }

        // 2. Tags
        $tagNames = ['Hardware', 'Guides', 'Maintenance', 'Coils', 'Safety', 'Technology'];
        $tags = [];
        foreach ($tagNames as $name) {
            $tags[$name] = BlogTag::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'slug' => Str::slug($name)]
            );
        }

        // 3. Blog Post 1: Complete Guide to Pod Systems
        $post1 = BlogPost::updateOrCreate(
            ['slug' => 'complete-guide-to-pod-systems-battery-life-coil-longevity'],
            [
                'category_id' => $categories['device-guides']->id,
                'author_id' => $author?->id,
                'title' => 'The Complete Guide to Pod Systems: Maximizing Battery Life & Coil Longevity',
                'slug' => 'complete-guide-to-pod-systems-battery-life-coil-longevity',
                'excerpt' => 'Learn essential charging hygiene, liquid viscosity matching, and priming routines to keep your coils tasting fresh and your battery health at peak capacity.',
                'content' => '
<h2>Getting the Most Out of Modern Pod Hardware</h2>
<p>Modern pod systems combine compact portability with sophisticated chipsets. However, coil burn and early battery degradation remain the two most common grievances users experience. With a few disciplined maintenance habits, you can easily double the lifespan of your replacement pods and preserve battery capacity for years.</p>

<h3>1. Master the 5-Minute Coil Priming Ritual</h3>
<p>Whenever you unbox a new pod or install a replacement coil, resist the temptation to fire the device immediately. Cotton wicking requires time to achieve full capillary saturation.</p>
<ul>
    <li>Fill the reservoir to approximately 85% capacity (avoid overflowing the central airway chimney).</li>
    <li>Let the pod rest upright on a flat surface for at least 8 to 10 minutes before inserting it into the battery chassis.</li>
    <li>Take two gentle "dry draws" through the mouthpiece without the battery connected to vacuum-draw liquid deep into the cotton fibers.</li>
</ul>

<h3>2. Smart Charging Hygiene</h3>
<p>Most modern pods utilize lithium-ion cells with fast USB-C inputs. While convenient, using laptop chargers or ultra-high wattage bricks (65W+) can heat the internal battery unnecessarily.</p>
<ul>
    <li>Always prefer a standard 5V/1A or 5V/2A wall adapter over high-voltage laptop power bricks.</li>
    <li>Never leave your pod charging overnight or exposed to direct sunlight on car dashboards.</li>
    <li>Disconnect the pod cartridge from the battery chassis while fast charging to prevent condensation buildup from warmer internal components.</li>
</ul>

<h3>3. Airflow Condensation Management</h3>
<p>Condensation is a normal physical byproduct of vapor cooling against metal contacts. Take a clean cotton swab or paper towel once every 48 hours to gently wipe the gold-plated connector pins inside the device chamber. Clean contacts ensure minimal voltage drop and instantaneous firing response.</p>
',
                'featured_image' => 'https://images.unsplash.com/photo-1526738549149-8e07eca6c147?auto=format&fit=crop&w=1200&q=85',
                'featured_image_alt' => 'Modern minimal electronic device hardware close up',
                'status' => 'published',
                'views_count' => 542,
                'published_at' => now()->subHours(6),
                'meta_title' => 'Guide to Pod Systems: Battery Care & Coil Longevity | INHALIQ',
                'meta_description' => 'Learn expert habits for coil priming, condensation cleanup, and battery charging to maximize your device performance and flavor clarity.',
            ]
        );
        $post1->tags()->sync([
            $tags['Hardware']->id,
            $tags['Guides']->id,
            $tags['Maintenance']->id,
            $tags['Coils']->id,
        ]);

        // 4. Blog Post 2: Mesh Coil vs Ceramic
        $post2 = BlogPost::updateOrCreate(
            ['slug' => 'mesh-coil-vs-ceramic-core-flavor-profile-comparison'],
            [
                'category_id' => $categories['tech-engineering']->id,
                'author_id' => $author?->id,
                'title' => 'Mesh Coils vs. Ceramic Cores: Which Delivers the Purest Flavor Profile?',
                'slug' => 'mesh-coil-vs-ceramic-core-flavor-profile-comparison',
                'excerpt' => 'A technical breakdown of heating surface area, thermal ramp-up times, and vapor density across the two leading heating element designs.',
                'content' => '
<h2>The Evolution of Heating Architecture</h2>
<p>The heating element inside your atomizer is the heart of your experience. In recent years, traditional round-wire spirals have been largely superseded by two distinct technologies: <strong>Perforated Mesh Strips</strong> and <strong>Porous Ceramic Cores</strong>. Let us examine how both perform across key operational metrics.</p>

<h3>Mesh Coils: Unmatched Ramp-Up Speed and Dense Clouds</h3>
<p>Mesh heating elements consist of a flat, laser-perforated metallic sheet (typically Kanthal A1, Nichrome 80, or SS316L). Because the mesh hugs an expansive strip of organic cotton, the surface area contact is up to 300% greater than classic single-wire coils.</p>
<ul>
    <li><strong>Near-Instant Ramp-Up:</strong> The thin metallic grid heats evenly across its entire plane in milliseconds, eliminating hot spots that cause dry hits.</li>
    <li><strong>Broad Flavor Spectrum:</strong> Mesh excels at rendering bright, multi-layered notes like citrus, fruit medleys, and iced beverages.</li>
</ul>

<h3>Ceramic Cores: Thermal Stability & Viscosity Handling</h3>
<p>Porous ceramic elements embed microscopic heating filaments within a porous ceramic matrix. This structure acts as both the wick and the heating chamber.</p>
<ul>
    <li><strong>Extreme Heat Tolerance:</strong> Ceramic is virtually immune to physical scorching, making dry hits far less catastrophic.</li>
    <li><strong>Warm, Rounded Profile:</strong> Ceramic delivers exceptionally smooth vapor that shines with rich tobacco blends and warm custard dessert notes.</li>
</ul>

<h3>The Verdict</h3>
<p>For crisp, vibrant notes and rapid cloud density, mesh continues to reign supreme. For thick oils, high-viscosity extracts, and ultra-smooth long draws, ceramic offers unparalleled durability.</p>
',
                'featured_image' => 'https://images.unsplash.com/photo-1550009158-9ebf69173e03?auto=format&fit=crop&w=1200&q=85',
                'featured_image_alt' => 'Precision electronic engineering circuit board macro',
                'status' => 'published',
                'views_count' => 891,
                'published_at' => now()->subDays(2),
                'meta_title' => 'Mesh Coils vs. Ceramic Cores Flavor Comparison | INHALIQ',
                'meta_description' => 'Explore the technical physics and flavor differences between perforated mesh strips and porous ceramic heating elements.',
            ]
        );
        $post2->tags()->sync([
            $tags['Hardware']->id,
            $tags['Technology']->id,
            $tags['Coils']->id,
        ]);

        // 5. Blog Post 3: Authenticity & Counterfeit Protection
        $post3 = BlogPost::updateOrCreate(
            ['slug' => 'authenticity-first-how-we-screen-hardware-and-protect-against-counterfeits'],
            [
                'category_id' => $categories['authenticity-safety']->id,
                'author_id' => $author?->id,
                'title' => 'Authenticity First: Our Rigorous Hardware Inspection & Anti-Counterfeit Standards',
                'slug' => 'authenticity-first-how-we-screen-hardware-and-protect-against-counterfeits',
                'excerpt' => 'Counterfeit devices pose critical battery and chemical hazards. Discover how our multi-stage authentication protocol guarantees 100% genuine hardware.',
                'content' => '
<h2>Why Authentic Hardware Matters More Than Ever</h2>
<p>In the consumer electronics landscape, cloned and counterfeit goods are not merely cosmetic copycats; they represent serious safety vulnerabilities. Low-grade counterfeit devices frequently cut corners with uncertified lithium-ion cells, missing circuit protection diodes, and non-food-grade heating alloys.</p>

<p>At INHALIQ, product safety and authenticity are non-negotiable. Every unit that enters our fulfillment centers must pass our three-pillar verification protocol before reaching a customer.</p>

<h3>Pillar 1: Direct Authorized Manufacturer Contracts</h3>
<p>We source 100% of our catalog inventory directly from authorized factory partners and certified master distributors. By eliminating grey-market third-party intermediaries, we ensure a transparent chain of custody from the production line straight to our shelves.</p>

<h3>Pillar 2: Holographic QR Code Verification</h3>
<p>Genuine products from tier-one manufacturers feature tamper-evident scratch-off panels containing unique cryptographic verification codes. During inbound receiving, our quality assurance team spot-checks randomly selected master cartons against the manufacturer\'s official security servers to validate authenticity.</p>

<h3>Pillar 3: Battery Cell Inspection and CE/RoHS Compliance</h3>
<p>We mandate that every rechargeable device in our store complies with international safety standards including CE, RoHS, and FCC certifications. Devices undergo thermal testing during internal sample reviews to ensure that short-circuit protection and 10-second cutoff timers operate flawlessly.</p>

<h3>What You Can Do as a Customer</h3>
<p>Always inspect the scratch-off authenticity code on your product packaging. Scratch the security panel gently with a coin and scan the QR code to verify on the official brand website. When you purchase through INHALIQ, you receive the full backing of our genuine authenticity guarantee.</p>
',
                'featured_image' => 'https://images.unsplash.com/photo-1563013544-824ae1b704d3?auto=format&fit=crop&w=1200&q=85',
                'featured_image_alt' => 'Security padlock and verification concept close up',
                'status' => 'published',
                'views_count' => 1140,
                'published_at' => now()->subDays(4),
                'meta_title' => 'Authenticity First: Our Safety Standards | INHALIQ',
                'meta_description' => 'Discover our 3-pillar authentication protocol that protects customers with 100% genuine verified hardware and battery safety certification.',
            ]
        );
        $post3->tags()->sync([
            $tags['Safety']->id,
            $tags['Guides']->id,
            $tags['Hardware']->id,
        ]);

        // Demo approved comments
        BlogComment::firstOrCreate(
            ['post_id' => $post1->id, 'author_email' => 'marcus.t@techgear.net'],
            [
                'post_id' => $post1->id,
                'author_name' => 'Marcus Thorne',
                'author_email' => 'marcus.t@techgear.net',
                'comment' => 'The tip about disconnecting the pod while fast charging made a night-and-day difference in reducing bottom condensation. Great practical advice!',
                'is_approved' => true,
            ]
        );

        BlogComment::firstOrCreate(
            ['post_id' => $post2->id, 'author_email' => 'elena.v@audioandtech.com'],
            [
                'post_id' => $post2->id,
                'author_name' => 'Elena Vance',
                'author_email' => 'elena.v@audioandtech.com',
                'comment' => 'Clear and concise comparison. The thermal ramp-up breakdown explains exactly why mesh produces that immediate crisp note response.',
                'is_approved' => true,
            ]
        );
    }
}
