/**
 * The editor side of the Client Navigator blocks. One generic editing screen for every block:
 * the block is drawn by the same component the front end uses (@clientnav/ui), each text value is
 * typed in place, and addresses, pictures and choices are set in the side panel.
 * What a block has is read from its block.json ("clientnav.fields"); adding a block needs no code here.
 */
import { registerBlockType } from "@wordpress/blocks";
import { InnerBlocks, InspectorControls, MediaUpload, MediaUploadCheck, RichText, useBlockProps, useInnerBlocksProps } from "@wordpress/block-editor";
import { BaseControl, Button, PanelBody, RangeControl, SelectControl, TextControl } from "@wordpress/components";
import { useEffect, useState } from "@wordpress/element";
import { useSelect } from "@wordpress/data";
import apiFetch from "@wordpress/api-fetch";
import { BLOCKS } from "@clientnav/ui/blocks";
import { META } from "../../packages/ui/src/fields";
import "./live.jsx";

// Links do not lead anywhere while editing.
const EditLink = ({ className, children }) => <span className={className}>{children}</span>;
const NoInner = () => null;
// The site's own form is not part of the editor; this stands where it will be.
const FormMock = () => <div className="cnu-form-mock">The site’s inquiry form appears here.</div>;

function makeInner(inner) {
  const template = Array.from({ length: inner.template }, () => [inner.allowed[0]]);
  return function Inner({ className }) {
    const props = useInnerBlocksProps({ className }, { allowedBlocks: inner.allowed, template, renderAppender: InnerBlocks.ButtonBlockAppender });
    return <div {...props} />;
  };
}

function useLatest(count, category) {
  const [cards, setCards] = useState([]);
  useEffect(() => {
    if (!count) return undefined;
    let live = true;
    const q = new URLSearchParams({ per_page: String(count || 3), ...(category ? { category } : {}) });
    apiFetch({ path: `/clientnav-headless/v1/posts?${q}` })
      .then((r) => live && setCards(r.items || []))
      .catch(() => live && setCards([]));
    return () => {
      live = false;
    };
  }, [count, category]);
  return cards;
}

function Control({ field, value, onChange }) {
  if (field.kind === "select") return <SelectControl __nextHasNoMarginBottom label={field.label} value={value} options={Object.entries(field.options).map(([v, label]) => ({ value: v, label }))} onChange={onChange} />;
  if (field.kind === "number") return <RangeControl __nextHasNoMarginBottom label={field.label} value={value} min={1} max={12} onChange={onChange} />;
  if (field.kind === "image") {
    return (
      <BaseControl __nextHasNoMarginBottom label={field.label}>
        <MediaUploadCheck>
          <MediaUpload
            allowedTypes={["image"]}
            value={value?.id}
            onSelect={(m) => onChange({ id: m.id, url: m.url, alt: m.alt || "", width: m.width, height: m.height })}
            render={({ open }) => (
              <div style={{ display: "grid", gap: 8 }}>
                {value?.url ? <img src={value.url} alt="" style={{ maxWidth: "100%", borderRadius: 4 }} /> : null}
                <div style={{ display: "flex", gap: 8 }}>
                  <Button variant="secondary" onClick={open}>
                    {value?.url ? "Replace" : "Choose a picture"}
                  </Button>
                  {value?.url ? (
                    <Button variant="tertiary" isDestructive onClick={() => onChange(undefined)}>
                      Remove
                    </Button>
                  ) : null}
                </div>
              </div>
            )}
          />
        </MediaUploadCheck>
      </BaseControl>
    );
  }
  return <TextControl __nextHasNoMarginBottom label={field.label} value={value || ""} onChange={onChange} help={field.kind === "link" ? "A path on this site (/contact/), a full address, tel: or #anchor." : undefined} />;
}

function makeEdit(name) {
  const meta = META[name];
  const fields = meta.clientnav.fields;
  const Component = BLOCKS[name];
  const Inner = meta.clientnav.inner ? makeInner(meta.clientnav.inner) : NoInner;
  const side = Object.entries(fields).filter(([, f]) => f.kind !== "text");

  // The child blocks' plain values, for blocks that count them (the roadmap).
  const plain = (b) => ({ name: b.name, attrs: b.attributes, innerBlocks: b.innerBlocks.map(plain) });

  return function Edit({ attributes, setAttributes, clientId }) {
    const root = useBlockProps();
    const children = useSelect((select) => select("core/block-editor").getBlocks(clientId), [clientId]);
    const latest = useLatest(name === "clientnav/posts" ? attributes.count : 0, attributes.category);
    const a = { ...attributes };
    for (const [key, f] of Object.entries(fields)) {
      if (f.kind === "text") {
        a[key] = <RichText tagName="span" value={attributes[key]} onChange={(v) => setAttributes({ [key]: v })} placeholder={f.label} allowedFormats={f.links ? ["core/bold", "core/italic", "core/link"] : ["core/bold", "core/italic"]} />;
      } else if (f.kind === "image") {
        const im = attributes[key];
        a[key] = im?.url ? { src: im.url, width: im.width, height: im.height, alt: im.alt || "", srcset: "" } : null;
      }
    }
    if (name === "clientnav/posts") a.cards = latest;
    return (
      <>
        {side.length ? (
          <InspectorControls>
            <PanelBody title={meta.title}>
              <div style={{ display: "grid", gap: 16 }}>
                {side.map(([key, f]) => (
                  <Control key={key} field={f} value={attributes[key]} onChange={(v) => setAttributes({ [key]: v })} />
                ))}
              </div>
            </PanelBody>
          </InspectorControls>
        ) : null}
        <Component a={a} Link={EditLink} Inner={Inner} inner={children.map(plain)} Form={FormMock} root={root} />
      </>
    );
  };
}

// A site can keep this block set out of its editor (Settings > Headless). WordPress then registers none of them
// and says so here; the script is still loaded, for the live preview panel alone.
const blocksOn = !(window.clientnavHeadless && window.clientnavHeadless.blocks === false);

for (const [name, meta] of Object.entries(blocksOn ? META : {})) {
  if (!BLOCKS[name]) continue;
  // eslint-disable-next-line @typescript-eslint/no-unused-vars -- the "clientnav" part of block.json is ours, not WordPress's
  const { clientnav, $schema, ...settings } = meta;
  registerBlockType(name, { ...settings, edit: makeEdit(name), save: clientnav.inner ? () => <InnerBlocks.Content /> : () => null });
}
