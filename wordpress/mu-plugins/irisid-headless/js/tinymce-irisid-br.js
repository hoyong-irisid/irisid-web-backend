/**
 * TinyMCE plugin: Shift+Enter (and toolbar BR) insert a single <br> line break.
 */
/* global tinymce */
tinymce.PluginManager.add("irisid_br", function (editor) {
  function insertBr() {
    editor.execCommand("mceInsertContent", false, "<br />");
  }

  editor.addButton("irisid_br", {
    title: "Line break (Shift+Enter)",
    text: "BR",
    onclick: insertBr,
  });

  editor.addMenuItem("irisid_br", {
    text: "Line break",
    context: "insert",
    onclick: insertBr,
  });

  editor.on("keydown", function (e) {
    var isEnter = e.keyCode === 13 || e.key === "Enter";
    if (!isEnter || !e.shiftKey) {
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === "function") {
      e.stopImmediatePropagation();
    }
    insertBr();
    return false;
  });
});
