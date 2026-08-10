<?php
// GET /invite/{code} — 카카오톡 공유·링크복사로 배포되는 초대 랜딩 페이지.
// 앱이 설치되어 있고 App Link 인증(assetlinks.json)이 통과하면 이 페이지는 열리지 않고
// 안드로이드가 바로 InviteJoinActivity를 띄운다. 이 페이지는 앱 미설치·인증 실패·PC 브라우저
// 등 App Link가 가로채지 못한 경우에만 보인다. .htaccess가 /invite/{code}를 ?code={code}로 재작성한다.
// og:* 태그는 카카오톡 채팅방 등에 이 URL이 텍스트로 붙여넣어졌을 때 미리보기 카드를 위한 것 —
// InviteShareSheet가 쓰는 FeedTemplate(직접 카드 지정)과는 별개 경로.
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';

use App\Repository\TripInviteRepository;

const PACKAGE_NAME = 'com.example.mybaghackathon';
const APP_ICON_URL = 'https://mybag.duckdns.org/assets/app_icon.png';
// TODO: Play Console 등록 완료 후 실제 스토어 URL로 채울 것 (서버현황.md 9번 섹션 참고)
const PLAY_STORE_URL = '';

$code = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['code'] ?? ''));

$invite = null;
if ($code !== '') {
    try {
        $invite = (new TripInviteRepository(get_db()))->findByCode($code);
    } catch (\Throwable $e) {
        $invite = null;
    }
}

$intentExtras = 'scheme=https;package=' . PACKAGE_NAME;
if (PLAY_STORE_URL !== '') {
    $intentExtras .= ';S.browser_fallback_url=' . rawurlencode(PLAY_STORE_URL);
}
$appLink = 'intent://mybag.duckdns.org/invite/' . rawurlencode($code) . '#Intent;' . $intentExtras . ';end';
$pageUrl = 'https://mybag.duckdns.org/invite/' . rawurlencode($code);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MyBag 여행방 초대</title>
<meta property="og:title" content="MyBag 여행방 초대">
<meta property="og:description" content="함께 짐을 챙겨요! 초대 링크를 눌러 여행방에 참여해 주세요.">
<meta property="og:image" content="<?= htmlspecialchars(APP_ICON_URL, ENT_QUOTES) ?>">
<meta property="og:url" content="<?= htmlspecialchars($pageUrl, ENT_QUOTES) ?>">
<style>
  :root{color-scheme:light}
  *{box-sizing:border-box}
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Apple SD Gothic Neo","Malgun Gothic",sans-serif;
    display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;
    background:radial-gradient(circle at 50% -10%, #eef4fc 0%, #dbe6f6 45%, #cddaf0 100%);
  }
  .card{
    background:#fff;border-radius:24px;padding:36px 28px 28px;max-width:360px;width:100%;
    text-align:center;box-shadow:0 20px 40px -12px rgba(23,41,79,.25);
  }
  .icon{width:112px;height:112px;border-radius:26px;box-shadow:0 8px 24px -6px rgba(23,41,79,.35);margin-bottom:18px}
  h1{font-size:19px;margin:0 0 8px;color:#16213e;font-weight:800}
  p{color:#5b6b8c;font-size:14px;line-height:1.55;margin:0}
  .code-pill{
    display:inline-block;margin-top:18px;padding:10px 20px;border-radius:999px;
    background:#eef2fb;color:#16213e;font-size:15px;font-weight:700;letter-spacing:2px;
  }
  a.btn{
    display:block;margin-top:22px;padding:15px;border-radius:14px;
    background:#FEE500;color:#191919;font-weight:800;text-decoration:none;font-size:15px;
    box-shadow:0 6px 16px -4px rgba(254,229,0,.6);
  }
  .footnote{margin-top:16px;font-size:12px;color:#9aa6c0}
</style>
</head>
<body>
<div class="card">
<?php if ($invite): ?>
  <img class="icon" src="<?= htmlspecialchars(APP_ICON_URL, ENT_QUOTES) ?>" alt="MyBag">
  <h1>여행방에 초대됐어요</h1>
  <p>내가방 앱에서 함께 짐을 챙겨보아요.<br>아래 버튼을 눌러 앱에서 참여해 주세요.</p>
  <span class="code-pill"><?= htmlspecialchars($code, ENT_QUOTES) ?></span>
  <a class="btn" href="<?= htmlspecialchars($appLink, ENT_QUOTES) ?>">내가방 앱에서 열기</a>
  <p class="footnote">앱이 설치되어 있지 않다면 먼저 설치한 뒤 초대 링크를 다시 눌러주세요.</p>
<?php else: ?>
  <img class="icon" src="<?= htmlspecialchars(APP_ICON_URL, ENT_QUOTES) ?>" alt="MyBag" style="filter:grayscale(1);opacity:.55">
  <h1>유효하지 않은 초대 링크</h1>
  <p>초대 코드가 만료되었거나 존재하지 않습니다.</p>
<?php endif; ?>
</div>
</body>
</html>
