/**
 * Fallback binder for ACF editors that skip mce_external_plugins registration.
 */
(function ($) {
  function insertBr(editor) {
    if (!editor || editor.removed) return;
    editor.execCommand("mceInsertContent", false, "<br />");
  }

  function bindShiftEnter(editor) {
    if (!editor || editor._irisidBrFallbackBound) return;
    editor._irisidBrFallbackBound = true;

    editor.on("keydown", function (e) {
      var isEnter = e.keyCode === 13 || e.key === "Enter";
      if (!isEnter || !e.shiftKey) return;
      e.preventDefault();
      e.stopPropagation();
      if (typeof e.stopImmediatePropagation === "function") {
        e.stopImmediatePropagation();
      }
      insertBr(editor);
      return false;
    });
  }

  $(document).on("tinymce-editor-setup", function (_event, editor) {
    bindShiftEnter(editor);
  });

  if (window.acf && typeof window.acf.addAction === "function") {
    window.acf.addAction("wysiwyg_tinymce_init", function (editor) {
      bindShiftEnter(editor);
    });
  }
})(jQuery);
