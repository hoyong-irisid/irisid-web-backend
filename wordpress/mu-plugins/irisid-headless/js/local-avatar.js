(function ($) {
  "use strict";

  $(function () {
    var $input = $("#irisid_local_avatar");
    var $remove = $("#irisid-local-avatar-remove");
    var frame;

    if (!$input.length) {
      return;
    }

    function setAvatarPreview(url) {
      $(".user-profile-picture img.avatar").attr({
        src: url,
        srcset: url,
      });
    }

    $("#irisid-local-avatar-upload").on("click", function (e) {
      e.preventDefault();

      if (frame) {
        frame.open();
        return;
      }

      frame = wp.media({
        title: "Choose profile picture",
        button: { text: "Use this image" },
        library: { type: "image" },
        multiple: false,
      });

      frame.on("select", function () {
        var attachment = frame.state().get("selection").first().toJSON();
        var url =
          (attachment.sizes &&
            ((attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) ||
              (attachment.sizes.medium && attachment.sizes.medium.url))) ||
          attachment.url;

        $input.val(attachment.id);
        setAvatarPreview(url);
        $remove.prop("disabled", false);
      });

      frame.open();
    });

    $remove.on("click", function (e) {
      e.preventDefault();
      $input.val("0");
      $remove.prop("disabled", true);
      // Placeholder until Update Profile saves the cleared meta.
      setAvatarPreview(
        "data:image/svg+xml," +
          encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect fill="#c3c4c7" width="96" height="96"/><circle fill="#fff" cx="48" cy="36" r="16"/><path fill="#fff" d="M16 84c0-18 14-28 32-28s32 10 32 28"/></svg>'
          )
      );
    });
  });
})(jQuery);
