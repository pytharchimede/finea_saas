document.addEventListener("DOMContentLoaded", function () {
  const editors = Array.from(document.querySelectorAll(".js-rich-editor"));
  if (!editors.length) return;

  const instances = [];
  const editorUrl = "https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js";

  function ensureEditorStyles() {
    if (document.getElementById("rich-text-easy-editor-style")) return;

    const style = document.createElement("style");
    style.id = "rich-text-easy-editor-style";
    style.textContent = `
      .js-rich-editor,
      .js-rich-editor .tox,
      .js-rich-editor .tox-editor-container,
      .js-rich-editor .tox-edit-area,
      .js-rich-editor iframe {
        background: #ffffff !important;
      }

      .js-rich-editor .tox.tox-tinymce {
        border: 1px solid rgb(226 232 240);
        border-radius: 1.5rem;
        overflow: hidden;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
      }

      .js-rich-editor .tox .tox-toolbar,
      .js-rich-editor .tox .tox-toolbar__primary,
      .js-rich-editor .tox .tox-menubar {
        background: #f8fafc !important;
      }

      .js-rich-editor textarea[data-rich-text-target] {
        display: block;
        width: 100%;
        min-height: 220px;
        border: 1px solid rgb(226 232 240);
        border-radius: 1.5rem;
        background: #fff;
        color: #1e293b;
        padding: 1rem 1.25rem;
      }
    `;
    document.head.appendChild(style);
  }

  function ensureEditorTextarea(wrapper, index) {
    const sourceTextarea = wrapper.querySelector(
      "textarea[data-rich-text-target]",
    );
    const legacyEditor = wrapper.querySelector("[data-rich-editor]");
    if (!sourceTextarea) return null;

    const initialHtml =
      sourceTextarea.value.trim() ||
      (legacyEditor ? legacyEditor.innerHTML.trim() : "");
    const textarea = document.createElement("textarea");
    textarea.name = sourceTextarea.name;
    textarea.value = initialHtml;
    textarea.id = sourceTextarea.id || "rh-rich-editor-" + String(index + 1);
    textarea.className =
      "min-h-[220px] w-full rounded-3xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 outline-none focus:border-emerald-400";
    textarea.setAttribute("data-rich-text-target", "1");

    wrapper.innerHTML = "";
    wrapper.classList.add("overflow-hidden");
    wrapper.appendChild(textarea);
    return textarea;
  }

  function loadScript(url) {
    return new Promise((resolve, reject) => {
      const existing = document.querySelector(
        'script[data-rich-editor-loader="richtext"]',
      );
      if (existing) {
        existing.addEventListener("load", resolve, { once: true });
        existing.addEventListener("error", reject, { once: true });
        if (window.tinymce && typeof window.tinymce.init === "function") {
          resolve();
        }
        return;
      }

      const script = document.createElement("script");
      script.src = url;
      script.async = true;
      script.referrerPolicy = "no-referrer";
      script.dataset.richEditorLoader = "richtext";
      script.addEventListener("load", resolve, { once: true });
      script.addEventListener("error", reject, { once: true });
      document.head.appendChild(script);
    });
  }

  function buildConfig() {
    return {
      height: 280,
      min_height: 220,
      menubar: "edit view insert format table",
      branding: false,
      promotion: false,
      browser_spellcheck: true,
      contextmenu: false,
      plugins:
        "autolink autosave charmap code fullscreen hr link lists paste preview searchreplace table visualblocks wordcount autoresize",
      toolbar:
        "undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link table hr | removeformat fullscreen preview",
      toolbar_sticky: true,
      statusbar: true,
      content_style:
        "body { font-family: Calibri, Arial, sans-serif; font-size: 14px; color: #1e293b; background: #ffffff; } p { margin: 0 0 10px; }",
      paste_as_text: false,
      convert_urls: false,
    };
  }

  async function initEditors() {
    ensureEditorStyles();

    const textareas = editors
      .map((wrapper, index) => ensureEditorTextarea(wrapper, index))
      .filter(Boolean);

    if (!textareas.length) return;

    try {
      await loadScript(editorUrl);
      const tinyMCE = window.tinymce;
      if (!tinyMCE || typeof tinyMCE.init !== "function") {
        throw new Error("TinyMCE indisponible");
      }

      for (const textarea of textareas) {
        const result = await tinyMCE.init({
          target: textarea,
          ...buildConfig(),
          setup(editor) {
            editor.on(
              "init change input undo redo keyup SetContent",
              function () {
                editor.save();
              },
            );
          },
        });
        if (Array.isArray(result) && result[0]) {
          instances.push(result[0]);
        }
      }
    } catch (error) {
      console.error("Chargement de l'éditeur riche impossible :", error);
    }

    document.querySelectorAll("form").forEach((form) => {
      form.addEventListener("submit", function () {
        instances.forEach((instance) => {
          if (instance && typeof instance.save === "function") {
            instance.save();
          }
        });
      });
    });
  }

  initEditors();
});
