import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

// One markdown file per homepage section, in src/content/home/.
// Prose lives in the markdown body; repeated structured copy (annotations,
// cards, list items) lives in frontmatter. Strings marked "inline markdown"
// support `code`, **bold**, and *emphasis* via src/lib/inline-md.ts.
const home = defineCollection({
  loader: glob({ pattern: '*.md', base: './src/content/home' }),
  schema: z.object({
    title: z.string().optional(),
    /** Raw HTML — the hero headline carries the brand-colored <em> spans. */
    title_html: z.string().optional(),
    /** Inline markdown. The italic caption under a code panel. */
    caption: z.string().optional(),
    ctas: z
      .array(
        z.object({
          label: z.string(),
          href: z.string(),
          style: z.enum(['solid', 'ghost']),
        }),
      )
      .optional(),
    /** The left-rail guided reading beside the GameRoom sample. */
    annotations: z
      .array(
        z.object({
          method: z.string(),
          kind: z.enum(['app', 'cli']),
          anchor: z.string(),
          text: z.string(),
        }),
      )
      .optional(),
    /** Pipeline cards in "How it works". Text is inline markdown. */
    stations: z
      .array(z.object({ label: z.string(), text: z.string() }))
      .optional(),
    /** Inline markdown. The trailing caption of "How it works". */
    note: z.string().optional(),
    /** The "Why you'd reach for it" cards. */
    quads: z
      .array(z.object({ title: z.string(), text: z.string() }))
      .optional(),
    /** Trust list items: bold lead, then the sentence. */
    items: z
      .array(z.object({ lead: z.string(), text: z.string() }))
      .optional(),
  }),
});

export const collections = { home };
