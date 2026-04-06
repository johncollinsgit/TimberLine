import {
  Banner,
  BlockStack,
  Box,
  Button,
  Card,
  Checkbox,
  Collapsible,
  Divider,
  InlineStack,
  Select,
  Text,
  TextField,
} from "@shopify/polaris";
import { useEffect, useMemo, useRef, useState, type ChangeEvent } from "react";
import type { EmailProductTile, EmailSection, EmailSectionType, MessagingMediaAsset } from "./types";

interface ProductResult {
  id: string;
  gid: string;
  title: string;
  image_url?: string | null;
  price?: string | null;
  url?: string | null;
}

interface EmailContentStepProps {
  subject: string;
  onSubjectChange: (value: string) => void;
  sections: EmailSection[];
  onSectionsChange: (sections: EmailSection[]) => void;
  mode: "sections" | "legacy_html";
  onModeChange: (mode: "sections" | "legacy_html") => void;
  advancedHtml: string;
  onAdvancedHtmlChange: (value: string) => void;
  searchProducts: (query: string) => Promise<ProductResult[]>;
  listMediaAssets: () => Promise<MessagingMediaAsset[]>;
  uploadMediaAsset: (file: File, altText?: string) => Promise<MessagingMediaAsset>;
}

const SECTION_TYPES: Array<{ type: EmailSectionType; label: string }> = [
  { type: "heading", label: "Heading" },
  { type: "text", label: "Body" },
  { type: "button", label: "Button" },
  { type: "product", label: "Product" },
  { type: "product_grid_4", label: "4 Products" },
  { type: "image", label: "Photo" },
  { type: "fading_divider", label: "Divider" },
];

function createSection(type: EmailSectionType): EmailSection {
  const id = `section_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;

  switch (type) {
    case "heading":
      return { id, type, text: "Heading", align: "left" };
    case "button":
      return { id, type, label: "Open", href: "", align: "left" };
    case "product":
      return {
        id,
        type,
        productId: "",
        title: "Product",
        imageUrl: "",
        price: "",
        href: "",
        buttonLabel: "Shop now",
      };
    case "product_grid_4":
      return {
        id,
        type,
        heading: "Shop the collection",
        products: [],
      };
    case "image":
      return {
        id,
        type,
        imageUrl: "",
        alt: "Photo",
        href: "",
        padding: "0 0 12px 0",
      };
    case "fading_divider":
      return {
        id,
        type,
        spacingTop: 12,
        spacingBottom: 12,
      };
    case "text":
    default:
      return { id, type: "text", html: "Add your message." };
  }
}

export function composeBodyFromSections(sections: EmailSection[]): string {
  const lines = sections
    .map((section) => {
      if (section.type === "heading") {
        return (section.text ?? "").trim();
      }
      if (section.type === "text") {
        return stripHtml(section.html ?? "").trim();
      }
      if (section.type === "product") {
        return [section.title ?? "", section.price ?? ""].join(" ").trim();
      }
      if (section.type === "product_grid_4") {
        return [
          section.heading ?? "",
          ...(section.products ?? []).map((product) => [product.title ?? "", product.price ?? ""].join(" ").trim()),
        ]
          .join(" ")
          .trim();
      }
      if (section.type === "button") {
        return [section.label ?? "", section.href ?? ""].join(" ").trim();
      }
      return "";
    })
    .map((line) => line.trim())
    .filter((line) => line.length > 0);

  return lines.join("\n\n").trim();
}

function stripHtml(value: string): string {
  return value.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
}

function sanitizeText(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function previewHtml(subject: string, sections: EmailSection[], mode: "sections" | "legacy_html", advancedHtml: string): string {
  if (mode === "legacy_html" && advancedHtml.trim() !== "") {
    return advancedHtml;
  }

  const rows: string[] = [];

  if (subject.trim() !== "") {
    rows.push(
      `<tr><td style="padding:0 0 14px 0;font-family:Arial,sans-serif;font-size:23px;font-weight:700;color:#0f172a;">${sanitizeText(
        subject,
      )}</td></tr>`,
    );
  }

  sections.forEach((section) => {
    if (section.type === "heading") {
      rows.push(
        `<tr><td style="padding:0 0 10px 0;font-family:Arial,sans-serif;font-size:20px;font-weight:700;color:#0f172a;text-align:${
          section.align ?? "left"
        }">${sanitizeText(section.text ?? "")}</td></tr>`,
      );
      return;
    }

    if (section.type === "text") {
      rows.push(
        `<tr><td style="padding:0 0 12px 0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;">${sanitizeText(
          stripHtml(section.html ?? ""),
        )}</td></tr>`,
      );
      return;
    }

    if (section.type === "button") {
      const label = sanitizeText(section.label ?? "Open");
      const href = sanitizeText(section.href ?? "#");
      rows.push(
        `<tr><td style="padding:10px 0;text-align:${section.align ?? "left"};"><a href="${href}" style="display:inline-block;background:#0b5d3f;color:#ffffff;text-decoration:none;border-radius:999px;padding:10px 16px;font-family:Arial,sans-serif;font-size:14px;font-weight:700;">${label}</a></td></tr>`,
      );
      return;
    }

    if (section.type === "image") {
      if ((section.imageUrl ?? "").trim() === "") {
        return;
      }
      const image = `<img src="${sanitizeText(section.imageUrl ?? "")}" alt="${sanitizeText(
        section.alt ?? "Image",
      )}" style="display:block;width:100%;height:auto;border-radius:10px;border:0;" />`;
      const wrapped = (section.href ?? "").trim() !== ""
        ? `<a href="${sanitizeText(section.href ?? "")}" style="text-decoration:none;">${image}</a>`
        : image;
      rows.push(`<tr><td style="padding:${sanitizeText(section.padding ?? "0 0 12px 0")};">${wrapped}</td></tr>`);
      return;
    }

    if (section.type === "product") {
      rows.push(
        `<tr><td style="padding:12px 0;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #dbe2ea;border-radius:12px;padding:12px;"><tr><td style="font-family:Arial,sans-serif;font-size:16px;font-weight:700;color:#0f172a;padding:0 0 6px 0;">${sanitizeText(
          section.title ?? "Product",
        )}</td></tr><tr><td style="font-family:Arial,sans-serif;font-size:14px;color:#334155;padding:0 0 8px 0;">${sanitizeText(
          section.price ?? "",
        )}</td></tr></table></td></tr>`,
      );
      return;
    }

    if (section.type === "product_grid_4") {
      const tiles = Array.isArray(section.products) ? section.products.slice(0, 4) : [];
      if (tiles.length === 0) {
        return;
      }

      const heading = (section.heading ?? "").trim() !== ""
        ? `<tr><td colspan="2" style="padding:0 0 12px 0;font-family:Arial,sans-serif;font-size:18px;font-weight:700;color:#0f172a;">${sanitizeText(
            section.heading ?? "",
          )}</td></tr>`
        : "";

      const rowsHtml: string[] = [];
      for (let index = 0; index < tiles.length; index += 2) {
        const pair = tiles.slice(index, index + 2);
        const cells = pair.map((product) => `
          <td valign="top" width="50%" style="width:50%;padding:0 8px 16px 8px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #dbe2ea;border-radius:12px;padding:12px;">
              <tr><td style="font-family:Arial,sans-serif;font-size:15px;font-weight:700;color:#0f172a;padding:0 0 6px 0;">${sanitizeText(product.title ?? "Product")}</td></tr>
              <tr><td style="font-family:Arial,sans-serif;font-size:14px;color:#334155;padding:0 0 8px 0;">${sanitizeText(product.price ?? "")}</td></tr>
            </table>
          </td>`);

        while (cells.length < 2) {
          cells.push(`<td valign="top" width="50%" style="width:50%;padding:0 8px 16px 8px;">&nbsp;</td>`);
        }

        rowsHtml.push(`<tr>${cells.join("")}</tr>`);
      }

      rows.push(`<tr><td style="padding:8px 0 12px 0;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0">${heading}${rowsHtml.join(
        "",
      )}</table></td></tr>`);
      return;
    }

    if (section.type === "fading_divider") {
      rows.push(
        `<tr><td style="padding:${section.spacingTop ?? 12}px 0 ${section.spacingBottom ?? 12}px 0;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td width="20%" style="width:20%;border-top:1px solid #eef2f7;font-size:0;line-height:0;">&nbsp;</td><td width="60%" style="width:60%;border-top:1px solid #dbe2ea;font-size:0;line-height:0;">&nbsp;</td><td width="20%" style="width:20%;border-top:1px solid #eef2f7;font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr>`,
      );
    }
  });

  if (rows.length === 0) {
    rows.push(
      `<tr><td style="padding:0 0 12px 0;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;">Start composing to preview your email.</td></tr>`,
    );
  }

  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f3f4f6;padding:16px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="620" cellspacing="0" cellpadding="0" style="width:100%;max-width:620px;background:#ffffff;border:1px solid #dbe2ea;border-radius:12px;padding:18px;">${rows.join(
    "",
  )}</table></td></tr></table></body></html>`;
}

interface MediaLibraryPickerProps {
  assets: MessagingMediaAsset[];
  selectedUrl?: string;
  loading: boolean;
  uploading: boolean;
  error: string | null;
  altText: string;
  label: string;
  onRefresh: () => void;
  onUpload: (file: File) => Promise<void>;
  onSelect: (asset: MessagingMediaAsset) => void;
}

function MediaLibraryPicker({
  assets,
  selectedUrl,
  loading,
  uploading,
  error,
  altText,
  label,
  onRefresh,
  onUpload,
  onSelect,
}: MediaLibraryPickerProps) {
  const inputRef = useRef<HTMLInputElement | null>(null);

  const handleUpload = async (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) {
      return;
    }

    await onUpload(file);
    event.target.value = "";
  };

  return (
    <BlockStack gap="200">
      <InlineStack align="space-between" blockAlign="center" wrap>
        <Text as="p" variant="bodyMd" fontWeight="semibold">
          {label}
        </Text>
        <InlineStack gap="200" wrap>
          <input
            ref={inputRef}
            type="file"
            accept="image/png,image/jpeg,image/jpg,image/gif,image/webp"
            hidden
            onChange={(event) => void handleUpload(event)}
          />
          <Button onClick={() => inputRef.current?.click()} loading={uploading}>
            Upload photo
          </Button>
          <Button onClick={onRefresh} loading={loading}>
            Refresh
          </Button>
        </InlineStack>
      </InlineStack>

      <Text as="p" tone="subdued" variant="bodySm">
        Upload once, then reuse saved photos across future email sections for this store.
      </Text>

      {error ? <Banner tone="critical">{error}</Banner> : null}

      {assets.length > 0 ? (
        <Box className="sf-messaging-media-grid">
          {assets.map((asset) => {
            const isSelected = selectedUrl === asset.url;
            const dimensions = asset.width && asset.height ? `${asset.width} x ${asset.height}` : null;

            return (
              <Card key={asset.id}>
                <BlockStack gap="200">
                  <Box className="sf-messaging-media-thumb">
                    <img src={asset.url} alt={asset.alt_text ?? (altText || "Saved media")} />
                  </Box>
                  <BlockStack gap="050">
                    <Text as="p" variant="bodySm" fontWeight="semibold">
                      {asset.original_name}
                    </Text>
                    <Text as="p" tone="subdued" variant="bodySm">
                      {[dimensions, asset.mime_type].filter(Boolean).join(" · ")}
                    </Text>
                  </BlockStack>
                  <Button variant={isSelected ? "primary" : "secondary"} onClick={() => onSelect(asset)}>
                    {isSelected ? "Selected" : "Use photo"}
                  </Button>
                </BlockStack>
              </Card>
            );
          })}
        </Box>
      ) : (
        <Banner tone="info">
          No saved photos yet. Upload one here and it will be available next time.
        </Banner>
      )}
    </BlockStack>
  );
}

function sectionDisplayName(section: EmailSection): string {
  switch (section.type) {
    case "product_grid_4":
      return "4 products";
    case "fading_divider":
      return "premium divider";
    default:
      return section.type;
  }
}

function productTileFromResult(product: ProductResult): EmailProductTile {
  return {
    productId: product.id,
    title: product.title,
    imageUrl: product.image_url ?? "",
    price: product.price ?? "",
    href: product.url ?? "",
    buttonLabel: "Shop now",
  };
}

export function EmailContentStep({
  subject,
  onSubjectChange,
  sections,
  onSectionsChange,
  mode,
  onModeChange,
  advancedHtml,
  onAdvancedHtmlChange,
  searchProducts,
  listMediaAssets,
  uploadMediaAsset,
}: EmailContentStepProps) {
  const [selectedSectionId, setSelectedSectionId] = useState<string | null>(sections[0]?.id ?? null);
  const [productQuery, setProductQuery] = useState("");
  const [productLoading, setProductLoading] = useState(false);
  const [productResults, setProductResults] = useState<ProductResult[]>([]);
  const [productError, setProductError] = useState<string | null>(null);
  const [mediaAssets, setMediaAssets] = useState<MessagingMediaAsset[]>([]);
  const [mediaLoading, setMediaLoading] = useState(false);
  const [mediaUploading, setMediaUploading] = useState(false);
  const [mediaError, setMediaError] = useState<string | null>(null);

  useEffect(() => {
    if (!selectedSectionId && sections.length > 0) {
      setSelectedSectionId(sections[0].id);
    }
  }, [sections, selectedSectionId]);

  const selectedSection = useMemo(
    () => sections.find((section) => section.id === selectedSectionId) ?? null,
    [sections, selectedSectionId],
  );

  useEffect(() => {
    if (!selectedSection || !["image", "product"].includes(selectedSection.type) || mediaAssets.length > 0 || mediaLoading) {
      return;
    }

    void loadMediaLibrary();
  }, [selectedSection, mediaAssets.length, mediaLoading]);

  const composedPreview = useMemo(
    () => previewHtml(subject, sections, mode, advancedHtml),
    [subject, sections, mode, advancedHtml],
  );

  const updateSection = (id: string, patch: Partial<EmailSection>) => {
    onSectionsChange(
      sections.map((section) => (section.id === id ? { ...section, ...patch } : section)),
    );
  };

  const updateGridProducts = (sectionId: string, products: EmailProductTile[]) => {
    updateSection(sectionId, { products: products.slice(0, 4) });
  };

  const addSection = (type: EmailSectionType) => {
    const next = [...sections, createSection(type)];
    onSectionsChange(next);
    setSelectedSectionId(next[next.length - 1]?.id ?? null);
  };

  const moveSection = (id: string, direction: "up" | "down") => {
    const index = sections.findIndex((section) => section.id === id);
    if (index < 0) {
      return;
    }

    const targetIndex = direction === "up" ? index - 1 : index + 1;
    if (targetIndex < 0 || targetIndex >= sections.length) {
      return;
    }

    const next = [...sections];
    const [row] = next.splice(index, 1);
    next.splice(targetIndex, 0, row);
    onSectionsChange(next);
  };

  const removeSection = (id: string) => {
    const next = sections.filter((section) => section.id !== id);
    onSectionsChange(next);
    if (selectedSectionId === id) {
      setSelectedSectionId(next[0]?.id ?? null);
    }
  };

  const runProductSearch = async () => {
    if (productQuery.trim().length < 2) {
      setProductResults([]);
      setProductError("Type at least 2 characters.");
      return;
    }

    setProductError(null);
    setProductLoading(true);

    try {
      const rows = await searchProducts(productQuery.trim());
      setProductResults(rows);
    } catch (error) {
      setProductResults([]);
      setProductError(error instanceof Error ? error.message : "Product search failed.");
    } finally {
      setProductLoading(false);
    }
  };

  const loadMediaLibrary = async () => {
    setMediaError(null);
    setMediaLoading(true);

    try {
      const assets = await listMediaAssets();
      setMediaAssets(assets);
    } catch (error) {
      setMediaError(error instanceof Error ? error.message : "Photo library failed to load.");
    } finally {
      setMediaLoading(false);
    }
  };

  const handleMediaUpload = async (sectionId: string, file: File, fallbackAlt: string) => {
    setMediaError(null);
    setMediaUploading(true);

    try {
      const asset = await uploadMediaAsset(file, fallbackAlt);
      setMediaAssets((previous) => [asset, ...previous.filter((entry) => entry.id !== asset.id)]);
      updateSection(sectionId, {
        imageUrl: asset.url,
        alt: asset.alt_text ?? fallbackAlt,
      });
    } catch (error) {
      setMediaError(error instanceof Error ? error.message : "Photo upload failed.");
    } finally {
      setMediaUploading(false);
    }
  };

  return (
    <BlockStack gap="400">
      <Card>
        <BlockStack gap="300">
          <TextField
            label="Subject"
            autoComplete="off"
            value={subject}
            maxLength={200}
            onChange={onSubjectChange}
          />
          <InlineStack gap="200" wrap>
            <Button
              pressed={mode === "sections"}
              onClick={() => onModeChange("sections")}
            >
              Sections
            </Button>
            <Button
              pressed={mode === "legacy_html"}
              onClick={() => onModeChange("legacy_html")}
            >
              Advanced HTML
            </Button>
          </InlineStack>
        </BlockStack>
      </Card>

      <Collapsible open={mode === "sections"} id="email-sections-builder">
        <Card>
          <BlockStack gap="400">
            <InlineStack align="space-between" blockAlign="center" wrap>
              <Text as="h3" variant="headingMd">
                Sections
              </Text>
              <InlineStack gap="200" wrap>
                {SECTION_TYPES.map((item) => (
                  <Button key={item.type} onClick={() => addSection(item.type)} size="slim">
                    Add {item.label}
                  </Button>
                ))}
              </InlineStack>
            </InlineStack>

            <Box className="sf-messaging-section-grid">
              <Box className="sf-messaging-section-list">
                <BlockStack gap="200">
                  {sections.length === 0 ? (
                    <Banner tone="info">Add a section to start composing.</Banner>
                  ) : (
                    sections.map((section, index) => (
                      <Card key={section.id}>
                        <InlineStack align="space-between" blockAlign="start" wrap>
                          <Button
                            variant="plain"
                            onClick={() => setSelectedSectionId(section.id)}
                          >
                            {index + 1}. {sectionDisplayName(section)}
                          </Button>
                          <InlineStack gap="100">
                            <Button size="slim" onClick={() => moveSection(section.id, "up")}>Up</Button>
                            <Button size="slim" onClick={() => moveSection(section.id, "down")}>Down</Button>
                            <Button size="slim" tone="critical" onClick={() => removeSection(section.id)}>
                              Remove
                            </Button>
                          </InlineStack>
                        </InlineStack>
                      </Card>
                    ))
                  )}
                </BlockStack>
              </Box>

              <Box className="sf-messaging-section-editor">
                {selectedSection ? (
                  <BlockStack gap="300">
                    <Text as="h4" variant="headingSm">
                      Edit {selectedSection.type}
                    </Text>

                    {selectedSection.type === "heading" ? (
                      <BlockStack gap="200">
                        <TextField
                          label="Heading"
                          value={selectedSection.text ?? ""}
                          onChange={(value) => updateSection(selectedSection.id, { text: value })}
                          autoComplete="off"
                        />
                        <Select
                          label="Alignment"
                          options={[
                            { label: "Left", value: "left" },
                            { label: "Center", value: "center" },
                            { label: "Right", value: "right" },
                          ]}
                          value={selectedSection.align ?? "left"}
                          onChange={(value) =>
                            updateSection(selectedSection.id, { align: value as "left" | "center" | "right" })
                          }
                        />
                      </BlockStack>
                    ) : null}

                    {selectedSection.type === "text" ? (
                      <TextField
                        label="Body"
                        value={selectedSection.html ?? ""}
                        onChange={(value) => updateSection(selectedSection.id, { html: value })}
                        autoComplete="off"
                        multiline={6}
                      />
                    ) : null}

                    {selectedSection.type === "button" ? (
                      <BlockStack gap="200">
                        <TextField
                          label="Label"
                          value={selectedSection.label ?? ""}
                          onChange={(value) => updateSection(selectedSection.id, { label: value })}
                          autoComplete="off"
                        />
                        <TextField
                          label="Link"
                          value={selectedSection.href ?? ""}
                          onChange={(value) => updateSection(selectedSection.id, { href: value })}
                          autoComplete="off"
                        />
                        <Select
                          label="Alignment"
                          options={[
                            { label: "Left", value: "left" },
                            { label: "Center", value: "center" },
                            { label: "Right", value: "right" },
                          ]}
                          value={selectedSection.align ?? "left"}
                          onChange={(value) =>
                            updateSection(selectedSection.id, { align: value as "left" | "center" | "right" })
                          }
                        />
                      </BlockStack>
                    ) : null}

                    {selectedSection.type === "image" ? (
                      <BlockStack gap="200">
                        <MediaLibraryPicker
                          assets={mediaAssets}
                          selectedUrl={selectedSection.imageUrl ?? ""}
                          loading={mediaLoading}
                          uploading={mediaUploading}
                          error={mediaError}
                          altText={selectedSection.alt ?? "Photo"}
                          label="Saved photos"
                          onRefresh={() => void loadMediaLibrary()}
                          onUpload={(file) => handleMediaUpload(selectedSection.id, file, selectedSection.alt ?? "Photo")}
                          onSelect={(asset) =>
                            updateSection(selectedSection.id, {
                              imageUrl: asset.url,
                              alt: asset.alt_text ?? selectedSection.alt ?? "Photo",
                            })
                          }
                        />
                        <Divider />
                        <TextField
                          label="Image URL"
                          value={selectedSection.imageUrl ?? ""}
                          onChange={(value) => updateSection(selectedSection.id, { imageUrl: value })}
                          autoComplete="off"
                        />
                        <TextField
                          label="Alt text"
                          value={selectedSection.alt ?? ""}
                          onChange={(value) => updateSection(selectedSection.id, { alt: value })}
                          autoComplete="off"
                        />
                        <TextField
                          label="Optional click link"
                          value={selectedSection.href ?? ""}
                          onChange={(value) => updateSection(selectedSection.id, { href: value })}
                          autoComplete="off"
                        />
                      </BlockStack>
                    ) : null}

                    {selectedSection.type === "product" ? (
                      <BlockStack gap="200">
                        <InlineStack gap="200" wrap>
                          <TextField
                            label="Search product"
                            autoComplete="off"
                            value={productQuery}
                            onChange={setProductQuery}
                          />
                          <Button loading={productLoading} onClick={runProductSearch}>
                            Search
                          </Button>
                        </InlineStack>

                        {productError ? <Text as="p" tone="critical">{productError}</Text> : null}

                        {productResults.length > 0 ? (
                          <Box className="sf-messaging-product-results">
                            {productResults.map((product) => (
                              <Card key={product.gid}>
                                <InlineStack align="space-between" blockAlign="start" wrap>
                                  <BlockStack gap="050">
                                    <Text as="p" variant="bodyMd" fontWeight="semibold">
                                      {product.title}
                                    </Text>
                                    <Text as="p" tone="subdued" variant="bodySm">
                                      {product.price ?? ""}
                                    </Text>
                                  </BlockStack>
                                  <Button
                                    size="slim"
                                    onClick={() =>
                                      updateSection(selectedSection.id, {
                                        productId: product.id,
                                        title: product.title,
                                        imageUrl: product.image_url ?? "",
                                        price: product.price ?? "",
                                        href: product.url ?? "",
                                      })
                                    }
                                  >
                                    Insert
                                  </Button>
                                </InlineStack>
                              </Card>
                            ))}
                          </Box>
                        ) : null}

                        <Divider />

                        <MediaLibraryPicker
                          assets={mediaAssets}
                          selectedUrl={selectedSection.imageUrl ?? ""}
                          loading={mediaLoading}
                          uploading={mediaUploading}
                          error={mediaError}
                          altText={selectedSection.title ?? "Product"}
                          label="Product photo"
                          onRefresh={() => void loadMediaLibrary()}
                          onUpload={(file) => handleMediaUpload(selectedSection.id, file, selectedSection.title ?? "Product")}
                          onSelect={(asset) =>
                            updateSection(selectedSection.id, {
                              imageUrl: asset.url,
                            })
                          }
                        />

                        <Divider />

                        <TextField
                          label="Title"
                          value={selectedSection.title ?? ""}
                          onChange={(value) => updateSection(selectedSection.id, { title: value })}
                          autoComplete="off"
                        />
                        <TextField
                          label="Price"
                          value={selectedSection.price ?? ""}
                          onChange={(value) => updateSection(selectedSection.id, { price: value })}
                          autoComplete="off"
                        />
                        <TextField
                          label="Image URL"
                          value={selectedSection.imageUrl ?? ""}
                          onChange={(value) => updateSection(selectedSection.id, { imageUrl: value })}
                          autoComplete="off"
                        />
                        <TextField
                          label="Link"
                          value={selectedSection.href ?? ""}
                          onChange={(value) => updateSection(selectedSection.id, { href: value })}
                          autoComplete="off"
                        />
                        <TextField
                          label="Button label"
                          value={selectedSection.buttonLabel ?? ""}
                          onChange={(value) => updateSection(selectedSection.id, { buttonLabel: value })}
                          autoComplete="off"
                        />
                      </BlockStack>
                    ) : null}

                    {selectedSection.type === "product_grid_4" ? (
                      <BlockStack gap="300">
                        <TextField
                          label="Module heading"
                          value={selectedSection.heading ?? ""}
                          onChange={(value) => updateSection(selectedSection.id, { heading: value })}
                          autoComplete="off"
                        />

                        <InlineStack gap="200" wrap>
                          <TextField
                            label="Search products"
                            autoComplete="off"
                            value={productQuery}
                            onChange={setProductQuery}
                          />
                          <Button loading={productLoading} onClick={runProductSearch}>
                            Search
                          </Button>
                        </InlineStack>

                        <Text as="p" tone="subdued" variant="bodySm">
                          Select up to 4 Shopify products. Each product tile keeps its own tracked link.
                        </Text>

                        {productError ? <Text as="p" tone="critical">{productError}</Text> : null}

                        {productResults.length > 0 ? (
                          <Box className="sf-messaging-product-results">
                            {productResults.map((product) => {
                              const selectedProducts = selectedSection.products ?? [];
                              const checked = selectedProducts.some((entry) => entry.productId === product.id);
                              const atLimit = selectedProducts.length >= 4;

                              return (
                                <Card key={product.gid}>
                                  <BlockStack gap="200">
                                    <InlineStack align="space-between" blockAlign="start" wrap>
                                      <BlockStack gap="050">
                                        <Text as="p" variant="bodyMd" fontWeight="semibold">
                                          {product.title}
                                        </Text>
                                        <Text as="p" tone="subdued" variant="bodySm">
                                          {product.price ?? ""}
                                        </Text>
                                      </BlockStack>
                                      <Checkbox
                                        label="Select"
                                        labelHidden
                                        checked={checked}
                                        disabled={!checked && atLimit}
                                        onChange={(value) => {
                                          const current = selectedSection.products ?? [];
                                          if (value) {
                                            updateGridProducts(selectedSection.id, [...current, productTileFromResult(product)]);
                                            return;
                                          }

                                          updateGridProducts(
                                            selectedSection.id,
                                            current.filter((entry) => entry.productId !== product.id),
                                          );
                                        }}
                                      />
                                    </InlineStack>
                                  </BlockStack>
                                </Card>
                              );
                            })}
                          </Box>
                        ) : null}

                        <Divider />

                        <Text as="h5" variant="headingSm">
                          Selected products ({(selectedSection.products ?? []).length}/4)
                        </Text>

                        {(selectedSection.products ?? []).length > 0 ? (
                          <BlockStack gap="200">
                            {(selectedSection.products ?? []).map((product, index, items) => (
                              <Card key={`${product.productId ?? "product"}_${index}`}>
                                <BlockStack gap="200">
                                  <InlineStack align="space-between" blockAlign="start" wrap>
                                    <BlockStack gap="050">
                                      <Text as="p" variant="bodyMd" fontWeight="semibold">
                                        {product.title ?? `Product ${index + 1}`}
                                      </Text>
                                      <Text as="p" tone="subdued" variant="bodySm">
                                        {product.price ?? ""}
                                      </Text>
                                    </BlockStack>
                                    <InlineStack gap="100">
                                      <Button
                                        size="slim"
                                        disabled={index === 0}
                                        onClick={() => {
                                          const next = [...items];
                                          const [row] = next.splice(index, 1);
                                          next.splice(index - 1, 0, row);
                                          updateGridProducts(selectedSection.id, next);
                                        }}
                                      >
                                        Up
                                      </Button>
                                      <Button
                                        size="slim"
                                        disabled={index === items.length - 1}
                                        onClick={() => {
                                          const next = [...items];
                                          const [row] = next.splice(index, 1);
                                          next.splice(index + 1, 0, row);
                                          updateGridProducts(selectedSection.id, next);
                                        }}
                                      >
                                        Down
                                      </Button>
                                      <Button
                                        size="slim"
                                        tone="critical"
                                        onClick={() =>
                                          updateGridProducts(
                                            selectedSection.id,
                                            items.filter((_, itemIndex) => itemIndex !== index),
                                          )
                                        }
                                      >
                                        Remove
                                      </Button>
                                    </InlineStack>
                                  </InlineStack>

                                  <TextField
                                    label="Button label"
                                    value={product.buttonLabel ?? ""}
                                    onChange={(value) => {
                                      const next = [...(selectedSection.products ?? [])];
                                      next[index] = { ...next[index], buttonLabel: value };
                                      updateGridProducts(selectedSection.id, next);
                                    }}
                                    autoComplete="off"
                                  />
                                </BlockStack>
                              </Card>
                            ))}
                          </BlockStack>
                        ) : (
                          <Banner tone="info">Search and select up to 4 products for this module.</Banner>
                        )}
                      </BlockStack>
                    ) : null}

                    {selectedSection.type === "fading_divider" ? (
                      <BlockStack gap="200">
                        <Select
                          label="Space above"
                          options={[
                            { label: "None", value: "0" },
                            { label: "Small", value: "8" },
                            { label: "Medium", value: "12" },
                            { label: "Large", value: "20" },
                            { label: "XL", value: "28" },
                          ]}
                          value={String(selectedSection.spacingTop ?? 12)}
                          onChange={(value) => updateSection(selectedSection.id, { spacingTop: Number(value) })}
                        />
                        <Select
                          label="Space below"
                          options={[
                            { label: "None", value: "0" },
                            { label: "Small", value: "8" },
                            { label: "Medium", value: "12" },
                            { label: "Large", value: "20" },
                            { label: "XL", value: "28" },
                          ]}
                          value={String(selectedSection.spacingBottom ?? 12)}
                          onChange={(value) => updateSection(selectedSection.id, { spacingBottom: Number(value) })}
                        />
                        <Text as="p" tone="subdued" variant="bodySm">
                          This uses an email-safe tapered divider fallback rather than a fragile CSS gradient.
                        </Text>
                      </BlockStack>
                    ) : null}
                  </BlockStack>
                ) : (
                  <Text as="p" tone="subdued">Select a section to edit it.</Text>
                )}
              </Box>
            </Box>
          </BlockStack>
        </Card>
      </Collapsible>

      <Collapsible open={mode === "legacy_html"} id="email-advanced-html">
        <Card>
          <BlockStack gap="200">
            <Text as="p" tone="subdued" variant="bodySm">
              Advanced HTML is optional and intended for edge cases.
            </Text>
            <TextField
              label="Advanced HTML"
              value={advancedHtml}
              onChange={onAdvancedHtmlChange}
              autoComplete="off"
              multiline={14}
            />
          </BlockStack>
        </Card>
      </Collapsible>

      <Card>
        <BlockStack gap="200">
          <Text as="h3" variant="headingMd">
            Live preview
          </Text>
          <iframe
            title="Email preview"
            className="sf-messaging-email-preview-frame"
            sandbox="allow-same-origin"
            srcDoc={composedPreview}
          />
        </BlockStack>
      </Card>
    </BlockStack>
  );
}
