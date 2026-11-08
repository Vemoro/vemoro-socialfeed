(function (blocks, element, components, blockEditor, serverSideRender, i18n) {
  "use strict";
  var el = element.createElement;
  var InspectorControls = blockEditor.InspectorControls;
  var useBlockProps = blockEditor.useBlockProps;
  var PanelBody = components.PanelBody;
  var RangeControl = components.RangeControl;
  var ToggleControl = components.ToggleControl;
  var SelectControl = components.SelectControl;
  var TextControl = components.TextControl;
  var ColorPalette = components.ColorPalette;
  var defaults = window.vemoroBlockDefaults || {};
  var palette = window.vemoroBlockPalette || [];
  var typography = window.vemoroBlockTypography || {
    fontSizes: [],
    fontFamilies: [],
  };
  function registerFeedBlock(blockName) {
    blocks.registerBlockType(blockName, {
      edit: function (props) {
        var a = props.attributes;
        var blockProps = useBlockProps({ className: "vemoro-block-editor" });
        function set(key) {
          return function (value) {
            var change = {};
            change[key] = value;
            props.setAttributes(change);
          };
        }
        function value(key) {
          return typeof a[key] === "undefined" ? defaults[key] : a[key];
        }
        var fontSizeOptions = [
          { label: i18n.__("Theme default", "vemoro-socialfeed"), value: "" },
        ].concat(
          (typography.fontSizes || []).map(function (item) {
            return { label: item.name, value: item.slug };
          }),
          [
            {
              label: i18n.__("Custom size", "vemoro-socialfeed"),
              value: "custom",
            },
          ],
        );
        var fontFamilyOptions = [
          { label: i18n.__("Theme default", "vemoro-socialfeed"), value: "" },
        ].concat(
          (typography.fontFamilies || []).map(function (item) {
            return { label: item.name, value: item.slug };
          }),
        );
        return el("div", blockProps, [
          el(InspectorControls, { key: "settings" }, [
            el(
              PanelBody,
              {
                key: "feed",
                title: i18n.__("Feed settings", "vemoro-socialfeed"),
                initialOpen: true,
              },
              el(TextControl, {
                label: i18n.__("Optional heading", "vemoro-socialfeed"),
                value: value("heading") || "",
                onChange: set("heading"),
              }),
              el(SelectControl, {
                label: i18n.__("Heading level", "vemoro-socialfeed"),
                value: String(value("heading_level") || 2),
                options: [
                  { label: "H2", value: "2" },
                  { label: "H3", value: "3" },
                  { label: "H4", value: "4" },
                  { label: "H5", value: "5" },
                  { label: "H6", value: "6" },
                ],
                onChange: function (level) {
                  set("heading_level")(parseInt(level, 10));
                },
              }),
              el(RangeControl, {
                label: i18n.__("Posts", "vemoro-socialfeed"),
                min: 1,
                max: 100,
                value: value("posts"),
                onChange: set("posts"),
              }),
              el(RangeControl, {
                label: i18n.__("Desktop columns", "vemoro-socialfeed"),
                min: 1,
                max: 6,
                value: value("columns"),
                onChange: set("columns"),
              }),
              el(RangeControl, {
                label: i18n.__("Tablet columns", "vemoro-socialfeed"),
                min: 1,
                max: 6,
                value: value("columns_tablet"),
                onChange: set("columns_tablet"),
              }),
              el(RangeControl, {
                label: i18n.__("Mobile columns", "vemoro-socialfeed"),
                min: 1,
                max: 4,
                value: value("columns_mobile"),
                onChange: set("columns_mobile"),
              }),
              el(ToggleControl, {
                label: i18n.__("Show caption", "vemoro-socialfeed"),
                checked: value("show_caption"),
                onChange: set("show_caption"),
              }),
              el(ToggleControl, {
                label: i18n.__("Show date", "vemoro-socialfeed"),
                checked: value("show_date"),
                onChange: set("show_date"),
              }),
              el(ToggleControl, {
                label: i18n.__("Show username", "vemoro-socialfeed"),
                checked: value("show_username"),
                onChange: set("show_username"),
              }),
              el(ToggleControl, {
                label: i18n.__(
                  "Show likes and comment counts",
                  "vemoro-socialfeed",
                ),
                checked: value("show_metrics"),
                onChange: set("show_metrics"),
              }),
              el(ToggleControl, {
                label: i18n.__("Show external link", "vemoro-socialfeed"),
                checked: value("show_link"),
                onChange: set("show_link"),
              }),
              el(SelectControl, {
                label: i18n.__("Aspect ratio", "vemoro-socialfeed"),
                value: value("aspect_ratio"),
                options: [
                  { label: "9:16 (Reel)", value: "9/16" },
                  { label: "1:1", value: "1/1" },
                  { label: "4/5", value: "4/5" },
                  { label: "16:9", value: "16/9" },
                  { label: "Auto", value: "auto" },
                ],
                onChange: set("aspect_ratio"),
              }),
              el(SelectControl, {
                label: i18n.__("Order", "vemoro-socialfeed"),
                value: value("order"),
                options: [
                  {
                    label: i18n.__("Newest first", "vemoro-socialfeed"),
                    value: "DESC",
                  },
                  {
                    label: i18n.__("Oldest first", "vemoro-socialfeed"),
                    value: "ASC",
                  },
                ],
                onChange: set("order"),
              }),
              el(TextControl, {
                label: i18n.__("Additional CSS class", "vemoro-socialfeed"),
                value: value("class"),
                onChange: set("class"),
              }),
            ),
            el(
              PanelBody,
              {
                key: "background",
                title: i18n.__("Section background", "vemoro-socialfeed"),
                initialOpen: false,
              },
              el(ColorPalette, {
                colors: palette,
                value: value("section_background"),
                onChange: set("section_background"),
                clearable: true,
                enableAlpha: false,
              }),
              el(ToggleControl, {
                label: i18n.__(
                  "Extend background across the full viewport width",
                  "vemoro-socialfeed",
                ),
                help: value("full_viewport_background")
                  ? i18n.__(
                      "The feed content keeps its normal width; only the background reaches both browser edges.",
                      "vemoro-socialfeed",
                    )
                  : i18n.__(
                      "The background is limited to the feed content width.",
                      "vemoro-socialfeed",
                    ),
                checked: !!value("full_viewport_background"),
                onChange: set("full_viewport_background"),
              }),
            ),
            el(
              PanelBody,
              {
                key: "heading-style",
                title: i18n.__("Heading typography", "vemoro-socialfeed"),
                initialOpen: false,
              },
              el(SelectControl, {
                label: i18n.__("Font family", "vemoro-socialfeed"),
                value: value("heading_font_family") || "",
                options: fontFamilyOptions,
                onChange: set("heading_font_family"),
              }),
              el(SelectControl, {
                label: i18n.__("Font size", "vemoro-socialfeed"),
                value: value("heading_font_size") || "",
                options: fontSizeOptions,
                onChange: set("heading_font_size"),
              }),
              value("heading_font_size") === "custom"
                ? el(RangeControl, {
                    label: i18n.__(
                      "Custom font size in pixels",
                      "vemoro-socialfeed",
                    ),
                    min: 12,
                    max: 120,
                    value: value("heading_custom_font_size") || 40,
                    onChange: set("heading_custom_font_size"),
                  })
                : null,
              el(SelectControl, {
                label: i18n.__("Font weight", "vemoro-socialfeed"),
                value: value("heading_weight") || "",
                options: [
                  {
                    label: i18n.__("Theme default", "vemoro-socialfeed"),
                    value: "",
                  },
                  {
                    label: i18n.__("Regular", "vemoro-socialfeed"),
                    value: "400",
                  },
                  { label: i18n.__("Bold", "vemoro-socialfeed"), value: "700" },
                ],
                onChange: set("heading_weight"),
              }),
              el(SelectControl, {
                label: i18n.__("Font style", "vemoro-socialfeed"),
                value: value("heading_style") || "",
                options: [
                  {
                    label: i18n.__("Theme default", "vemoro-socialfeed"),
                    value: "",
                  },
                  {
                    label: i18n.__("Normal", "vemoro-socialfeed"),
                    value: "normal",
                  },
                  {
                    label: i18n.__("Italic", "vemoro-socialfeed"),
                    value: "italic",
                  },
                ],
                onChange: set("heading_style"),
              }),
              el(SelectControl, {
                label: i18n.__("Alignment", "vemoro-socialfeed"),
                value: value("heading_align") || "",
                options: [
                  {
                    label: i18n.__("Theme default", "vemoro-socialfeed"),
                    value: "",
                  },
                  {
                    label: i18n.__("Left", "vemoro-socialfeed"),
                    value: "left",
                  },
                  {
                    label: i18n.__("Center", "vemoro-socialfeed"),
                    value: "center",
                  },
                  {
                    label: i18n.__("Right", "vemoro-socialfeed"),
                    value: "right",
                  },
                ],
                onChange: set("heading_align"),
              }),
              el(
                "p",
                { className: "components-base-control__label" },
                i18n.__("Text color", "vemoro-socialfeed"),
              ),
              el(ColorPalette, {
                colors: palette,
                value: value("heading_color"),
                onChange: set("heading_color"),
                clearable: true,
                enableAlpha: false,
              }),
              el(RangeControl, {
                label: i18n.__(
                  "Space below heading in pixels",
                  "vemoro-socialfeed",
                ),
                min: 0,
                max: 120,
                value: value("heading_spacing"),
                onChange: set("heading_spacing"),
              }),
            ),
          ]),
          el(
            "div",
            { key: "preview", className: "vemoro-block-editor__preview" },
            el(serverSideRender, { block: blockName, attributes: a }),
          ),
        ]);
      },
      save: function () {
        return null;
      },
    });
  }
  registerFeedBlock("vemoro-socialfeed/feed");
})(
  window.wp.blocks,
  window.wp.element,
  window.wp.components,
  window.wp.blockEditor,
  window.wp.serverSideRender,
  window.wp.i18n,
);
