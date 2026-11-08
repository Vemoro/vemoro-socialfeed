(function () {
  "use strict";

  var sync = document.getElementById("vemoro-sync-now");
  var progress = document.getElementById("vemoro-sync-progress");
  var result = document.getElementById("vemoro-sync-result");

  function request(action) {
    var data = new URLSearchParams({
      action: action,
      nonce: vemoroAdmin.nonce,
    });
    return fetch(vemoroAdmin.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: data.toString(),
    }).then(function (response) {
      return response.json();
    });
  }

  if (sync) {
    sync.addEventListener("click", function () {
      sync.disabled = true;
      progress.hidden = false;
      result.textContent = "";
      var timer = setInterval(function () {
        request("vemoro_sync_progress").then(function (reply) {
          if (reply.success && reply.data) {
            progress.querySelector("span").style.width =
              (reply.data.percent || 0) + "%";
            progress.setAttribute(
              "aria-label",
              (reply.data.phase || "") + " " + (reply.data.percent || 0) + "%",
            );
          }
        });
      }, 750);
      request("vemoro_sync")
        .then(function (reply) {
          clearInterval(timer);
          progress.querySelector("span").style.width = "100%";
          result.textContent = JSON.stringify(reply.data, null, 2);
          sync.disabled = false;
        })
        .catch(function () {
          clearInterval(timer);
          result.textContent = vemoroAdmin.syncError;
          sync.disabled = false;
        });
    });
  }

  var clear = document.getElementById("vemoro-clear-cache");
  if (clear) {
    clear.addEventListener("click", function () {
      request("vemoro_clear_cache").then(function (reply) {
        clear.textContent = reply.data.message;
      });
    });
  }

  var providers = document.querySelectorAll(".vemoro-oauth-provider");
  var hostedSettings = document.getElementById("vemoro-hosted-oauth-settings");
  var customSettings = document.getElementById("vemoro-custom-app-settings");
  function toggleOAuthSettings() {
    var selected = document.querySelector(".vemoro-oauth-provider:checked");
    var provider = selected ? selected.value : "vemoro";
    if (hostedSettings) {
      hostedSettings.hidden = provider !== "vemoro";
    }
    if (customSettings) {
      customSettings.hidden = provider !== "custom";
    }
  }
  providers.forEach(function (provider) {
    provider.addEventListener("change", toggleOAuthSettings);
  });
  toggleOAuthSettings();
})();
