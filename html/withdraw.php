<?php
// 웹 회원 탈퇴 페이지 — Play 스토어 "앱 없이도 탈퇴 요청 가능" 요건. DB는 직접 안 건드리고 KAKAO_JS_KEY만 필요해서
// bootstrap/app.php(DB 연결·에러핸들러 포함) 대신 environment.php만 불러옴
declare(strict_types=1);
require_once __DIR__ . '/../config/environment.php';
$env = load_env();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>내가방 회원 탈퇴</title>
<style>
  body { font-family: -apple-system, "Malgun Gothic", sans-serif; max-width: 520px; margin: 60px auto; padding: 0 20px; line-height: 1.7; color: #222; }
  h1 { font-size: 20px; }
  .warning { background: #fff4f4; border-left: 3px solid #e05656; padding: 14px 16px; font-size: 13.5px; margin: 20px 0; }
  .warning li { margin: 4px 0; }
  label { display: flex; align-items: flex-start; gap: 8px; font-size: 13.5px; margin: 16px 0; cursor: pointer; }
  button { width: 100%; padding: 14px; font-size: 15px; border: none; border-radius: 8px; background: #FEE500; color: #191919; font-weight: 600; cursor: pointer; }
  button:disabled { background: #eee; color: #999; cursor: not-allowed; }
  #result { margin-top: 20px; font-size: 13.5px; padding: 12px 14px; border-radius: 6px; display: none; }
  #result.success { display: block; background: #f0f9f0; color: #2a7a2a; }
  #result.error { display: block; background: #fdf0f0; color: #b23a3a; }
</style>
</head>
<body>

<h1>내가방(MyBag) 회원 탈퇴</h1>
<p>앱을 삭제했거나 접속이 어려운 경우, 이 페이지에서 카카오 로그인으로 본인 확인 후 탈퇴를 요청할 수 있습니다.</p>

<div class="warning">
  <strong>탈퇴 전 꼭 확인하세요</strong>
  <ul>
    <li>본인이 방장인 여행방은 <strong>즉시 완전히 삭제</strong>되며 복구할 수 없습니다.</li>
    <li>참여 중인 다른 여행방에서는 나가기 처리되며, 개인 준비물 목록이 삭제됩니다.</li>
    <li>계정 자체는 30일간 보존되며, 이 기간 내 재로그인하면 복구할 수 있습니다.</li>
  </ul>
</div>

<label>
  <input type="checkbox" id="agree">
  위 내용을 확인했으며, 탈퇴를 진행합니다.
</label>

<button id="withdrawBtn" disabled>카카오로 로그인하고 탈퇴하기</button>

<div id="result"></div>

<script src="https://t1.kakaocdn.net/kakao_js_sdk/2.7.4/kakao.min.js"></script>
<script>
  Kakao.init('<?= htmlspecialchars($env['KAKAO_JS_KEY'], ENT_QUOTES, 'UTF-8') ?>');

  var agree = document.getElementById('agree');
  var btn = document.getElementById('withdrawBtn');
  var result = document.getElementById('result');

  agree.addEventListener('change', function () {
    btn.disabled = !agree.checked;
  });

  btn.addEventListener('click', function () {
    btn.disabled = true;
    btn.textContent = '처리 중...';

    Kakao.Auth.login({
      success: function (authObj) {
        fetch('/api/auth/withdraw-web.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ access_token: authObj.access_token })
        })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            result.className = data.success ? 'success' : 'error';
            result.textContent = data.message;
            btn.textContent = '카카오로 로그인하고 탈퇴하기';
            btn.disabled = data.success ? true : !agree.checked;
          })
          .catch(function () {
            result.className = 'error';
            result.textContent = '요청 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해주세요.';
            btn.textContent = '카카오로 로그인하고 탈퇴하기';
            btn.disabled = !agree.checked;
          });
      },
      fail: function () {
        result.className = 'error';
        result.textContent = '카카오 로그인에 실패했습니다.';
        btn.textContent = '카카오로 로그인하고 탈퇴하기';
        btn.disabled = !agree.checked;
      }
    });
  });
</script>

</body>
</html>
