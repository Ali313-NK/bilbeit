// Anonymous visit counter for the /admin dashboard. No cookies; sends page, referrer and utm_source only.
(function () {
  if (location.protocol !== "https:" && location.hostname !== "localhost") return;
  var q = new URLSearchParams(location.search);
  var src = q.get("utm_source") || "";
  try { if (src) sessionStorage.setItem("bb_src", src); else src = sessionStorage.getItem("bb_src") || ""; } catch (e) {}
  var ref = document.referrer && document.referrer.indexOf(location.host) === -1 ? document.referrer : "";
  function send(ev) {
    var data = new URLSearchParams({ e: ev, p: location.pathname, r: ref, s: src });
    if (navigator.sendBeacon) navigator.sendBeacon("/t.php", data);
    else fetch("/t.php", { method: "POST", body: data, keepalive: true }).catch(function () {});
  }
  send("view");
  document.addEventListener("click", function (e) {
    var a = e.target.closest && e.target.closest("a, button");
    if (!a) return;
    if (a.matches("[data-checkout], a[href*='order.html']")) send("buy");
    else if (a.id === "copy") send("copy");
  });
})();
