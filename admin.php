<?php
require_once __DIR__ . '/includes/bootstrap.php';

const SGK_ADMIN_SESSION_KEY = 'sgk_admin_authenticated';
const SGK_ADMIN_FLASH_KEY = 'sgk_admin_flash';

function sgk_admin_hash_password($salt, $password) {
	return hash('sha512', $salt . $password);
}

function sgk_read_csv_table($path) {
	if (!is_file($path)) {
		return [
			'headers' => [],
			'rows'    => [],
			'error'   => 'Datoteka s prijavami še ne obstaja.',
		];
	}

	$handle = fopen($path, 'rb');
	if ($handle === false) {
		return [
			'headers' => [],
			'rows'    => [],
			'error'   => 'Datoteke s prijavami ni bilo mogoče odpreti.',
		];
	}

	$rawRows = [];
	while (($row = fgetcsv($handle, 0, ';')) !== false) {
		if ($row === [null] || $row === []) {
			continue;
		}
		$rawRows[] = $row;
	}
	fclose($handle);

	if ($rawRows === []) {
		return [
			'headers' => [],
			'rows'    => [],
			'error'   => null,
		];
	}

	$headers = array_map(static fn($value) => trim((string)$value), array_shift($rawRows));

	$maxColumns = count($headers);
	foreach ($rawRows as $row) {
		$maxColumns = max($maxColumns, count($row));
	}

	for ($i = count($headers); $i < $maxColumns; $i++) {
		$headers[] = 'extra_' . ($i + 1);
	}

	$rows = [];
	foreach ($rawRows as $row) {
		$row = array_pad($row, $maxColumns, '');
		$assoc = [];
		foreach ($headers as $index => $header) {
			$assoc[$header] = sgk_csv_decode_value($header, trim((string)($row[$index] ?? '')));
		}
		$rows[] = $assoc;
	}

	return [
		'headers' => $headers,
		'rows'    => $rows,
		'error'   => null,
	];
}

function sgk_admin_write_csv_table($path, $headers, $rows) {
	$dir = dirname($path);
	if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
		return false;
	}

	$stream = fopen('php://temp', 'w+b');
	if ($stream === false) {
		return false;
	}

	$ok = fputcsv($stream, $headers, ';') !== false;
	if ($ok) {
		foreach ($rows as $row) {
			$orderedRow = [];
			foreach ($headers as $header) {
				$orderedRow[] = sgk_csv_encode_value($header, (string)($row[$header] ?? ''));
			}

			if (fputcsv($stream, $orderedRow, ';') === false) {
				$ok = false;
				break;
			}
		}
	}

	if (!$ok) {
		fclose($stream);
		return false;
	}

	rewind($stream);
	$content = stream_get_contents($stream);
	fclose($stream);

	if (!is_string($content)) {
		return false;
	}

	$handle = fopen($path, 'c+b');
	if ($handle === false) {
		return false;
	}

	if (!flock($handle, LOCK_EX)) {
		fclose($handle);
		return false;
	}

	if (!ftruncate($handle, 0)) {
		flock($handle, LOCK_UN);
		fclose($handle);
		return false;
	}

	rewind($handle);
	$ok = fwrite($handle, $content) !== false;
	fflush($handle);
	flock($handle, LOCK_UN);
	fclose($handle);

	return $ok;
}

function sgk_admin_find_row_index($table, $requestedIndex, $originalSubmittedAt, $originalEmail) {
	$rows = $table['rows'] ?? [];
	$requestedIndex = filter_var($requestedIndex, FILTER_VALIDATE_INT);
	$originalSubmittedAt = trim((string)$originalSubmittedAt);
	$originalEmail = trim((string)$originalEmail);
	$hasFingerprint = $originalSubmittedAt !== '' || $originalEmail !== '';

	if ($requestedIndex !== false && isset($rows[$requestedIndex])) {
		$row = $rows[$requestedIndex];
		if (!$hasFingerprint ||
			(trim((string)($row['submitted_at'] ?? '')) === $originalSubmittedAt &&
				trim((string)($row['email'] ?? '')) === $originalEmail)) {
			return $requestedIndex;
		}
	}

	if ($hasFingerprint) {
		foreach ($rows as $index => $row) {
			if (trim((string)($row['submitted_at'] ?? '')) === $originalSubmittedAt &&
				trim((string)($row['email'] ?? '')) === $originalEmail) {
				return $index;
			}
		}
	}

	return null;
}

function sgk_admin_set_flash($type, $message) {
	$_SESSION[SGK_ADMIN_FLASH_KEY] = [
		'type'    => $type,
		'message' => $message,
	];
}

function sgk_admin_sort_query($source) {
	$allowedSorts = [
		'sequence',
		'submitted_at',
		'name',
		'institution',
		'payment_method',
		'contact',
		'presentation_type',
	];
	$sort = trim((string)($source['sort'] ?? ''));
	$dir = trim((string)($source['dir'] ?? ''));

	if (!in_array($sort, $allowedSorts, true)) {
		return '';
	}

	$dir = $dir === 'desc' ? 'desc': 'asc';
	return '?' . http_build_query([
			'sort' => $sort,
			'dir'  => $dir,
		]);
}

function sgk_admin_redirect($query = '') {
	header('Location: /admin' . $query);
	exit;
}

function sgk_format_admin_label($column) {
	$labels = [
		'submitted_at'        => 'Oddano',
		'first_name'          => 'Ime',
		'last_name'           => 'Priimek',
		'institution'         => 'Ustanova',
		'address'             => 'Naslov',
		'invoice_same'        => 'Račun enak',
		'invoice_name'        => 'Naziv za račun',
		'invoice_address'     => 'Naslov za račun',
		'invoice_post'        => 'Pošta za račun',
		'invoice_country'     => 'Država za račun',
		'vat_payer'           => 'Davčni zavezanec',
		'vat_id'              => 'ID za DDV',
		'email'               => 'E-mail',
		'postal_address'      => 'Poštni naslov',
		'phone'               => 'Telefon',
		'registration_type'   => 'Kotizacija',
		'mid_excursion'       => 'Medkongresna ekskurzija',
		'post_excursion'      => 'Pokongresna ekskurzija',
		'photo_contest'       => 'Foto natečaj',
		'payment_method'      => 'Način plačila',
		'shirt_gender'        => 'Model majice',
		'shirt_size'          => 'Velikost majice',
		'diet_vegetarian'     => 'Vegetarijanska prehrana',
		'diet_lactose'        => 'Brez laktoze',
		'diet_gluten'         => 'Brez glutena',
		'diet_none'           => 'Brez omejitev',
		'diet_other'          => 'Drugo prehrana',
		'presentation_type'   => 'Predstavitev',
		'notes'               => 'Opombe',
		'total_eur'           => 'Skupaj EUR',
		'upn_qr_included'     => 'UPN QR',
		'abstract_later'      => 'Povzetek kasneje',
		'contact_name'        => 'Kontaktna oseba',
		'title'               => 'Naslov prispevka',
		'authors'             => 'Avtorji',
		'institutions'        => 'Institucije',
		'keywords'            => 'Ključne besede',
		'keyword_count'       => 'Št. ključnih besed',
		'abstract_text'       => 'Povzetek',
		'abstract_word_count' => 'Št. besed',
	];

	return $labels[$column] ?? ucwords(str_replace('_', ' ', $column));
}

function sgk_admin_editable_columns() {
	return [
		'first_name',
		'last_name',
		'institution',
		'address',
		'invoice_same',
		'invoice_name',
		'invoice_address',
		'invoice_post',
		'invoice_country',
		'vat_payer',
		'vat_id',
		'email',
		'phone',
		'registration_type',
		'mid_excursion',
		'post_excursion',
		'photo_contest',
		'payment_method',
		'shirt_gender',
		'shirt_size',
		'diet_vegetarian',
		'diet_lactose',
		'diet_gluten',
		'diet_none',
		'diet_other',
		'presentation_type',
		'title',
		'abstract_later',
		'authors',
		'institutions',
		'keywords',
		'abstract_text',
		'notes',
	];
}

function sgk_admin_zero_as_empty_column($column) {
	return in_array($column, [
		'title',
		'authors',
		'institutions',
		'keywords',
		'keyword_count',
		'abstract_text',
		'abstract_word_count',
		'notes',
	], true);
}

function sgk_admin_clean_field_value($column, $value) {
	$value = trim((string)$value);
	if ($value === '0' && sgk_admin_zero_as_empty_column($column)) {
		return '';
	}

	return $value;
}

function sgk_admin_is_boolean_column($column) {
	return in_array($column, [
			'invoice_same',
			'vat_payer',
			'post_excursion',
			'photo_contest',
			'diet_vegetarian',
			'diet_lactose',
			'diet_gluten',
			'diet_none',
			'upn_qr_included',
		], true);
}

function sgk_format_admin_datetime($value) {
	$timestamp = strtotime($value);
	if ($timestamp === false) {
		return null;
	}

	return date('d.m.Y H:i:s', $timestamp);
}

function sgk_format_admin_value($column, $value) {
	if ($value === '') {
		return [
			'html'  => '—',
			'class' => 'is-empty',
		];
	}

	if ($column === 'submitted_at') {
		$formatted = sgk_format_admin_datetime($value);
		if ($formatted !== null) {
			return [
				'html'  => e($formatted),
				'class' => 'is-date',
			];
		}
	}

	if (sgk_admin_is_boolean_column($column)) {
		$truthy = in_array(strtolower($value), [
			'1',
			'true',
			'yes',
			'da'
		], true);

		return [
			'html'  => $truthy ? '<span class="admin-bool admin-bool-yes" aria-label="Da">☑</span>':
				'<span class="admin-bool admin-bool-no" aria-label="Ne">✕</span>',
			'class' => 'is-boolean',
		];
	}

	return [
		'html'  => e($value),
		'class' => '',
	];
}

function sgk_admin_row_full_name($row) {
	$fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
	if ($fullName !== '') {
		return $fullName;
	}

	return trim((string)($row['contact_name'] ?? ''));
}

function sgk_admin_summary_value($row, $key) {
	if ($key === 'name') {
		return sgk_admin_row_full_name($row);
	}

	if ($key === 'submitted_at') {
		return sgk_format_admin_datetime((string)($row['submitted_at'] ?? '')) ?? '';
	}

	return trim((string)($row[$key] ?? ''));
}

function sgk_admin_preview_value($column, $value) {
	$value = sgk_admin_clean_field_value($column, $value);
	if ($value === '') {
		return '';
	}

	if ($column === 'submitted_at') {
		return sgk_format_admin_datetime($value) ?? $value;
	}

	if (sgk_admin_is_boolean_column($column)) {
		return in_array(strtolower($value), [
			'1',
			'true',
			'yes',
			'da'
		], true) ? 'Da': 'Ne';
	}

	return $value;
}

function sgk_admin_edit_fields($headers, $row) {
	$fields = [];
	$editableColumns = array_values(array_intersect(sgk_admin_editable_columns(), $headers));
	$selectOptions = [
		'registration_type' => [
			''                   => 'Izberite',
			'redna-zgodnja'      => 'Redna zgodnja (350,00)',
			'redna-pozna'        => 'Redna pozna (450,00)',
			'redna-zgodnja-sgd'  => 'Redna zgodnja za člane SGD (300,00)',
			'redna-pozna-sgd'    => 'Redna pozna za člane SGD (400,00)',
			'studentska-zgodnja' => 'Študentska/upokojenska zgodnja (200,00)',
			'studentska-pozna'   => 'Študentska/upokojenska pozna (250,00)',
		],
		'payment_method'    => [
			''                                     => 'Izberite',
			'bančno nakazilo'                      => 'Bančno nakazilo',
			'naročilnica - plačilo po računu'      => 'Naročilnica - plačilo po računu',
			'drugo'                                => 'Drugo',
		],
		'mid_excursion'     => [
			'none'      => 'Brez izbire (0,00)',
			'kobilarna' => 'Ogled kobilarne Lipica (16,00)',
			'kamnolom'  => 'Ogled kamnoloma Lipica (0,00)',
		],
		'shirt_gender'      => [
			''       => 'Izberite',
			'Ženska' => 'Ženska',
			'Moška'  => 'Moška',
		],
		'shirt_size'        => [
			''    => 'Izberite',
			'XS'  => 'XS',
			'S'   => 'S',
			'M'   => 'M',
			'L'   => 'L',
			'XL'  => 'XL',
			'XXL' => 'XXL',
		],
		'presentation_type' => [
			'Predavanje'          => 'Predavanje',
			'Plakat'              => 'Plakat',
			'Brez predstavitve'   => 'Brez predstavitve',
		],
	];

	foreach ($editableColumns as $header) {
		$value = sgk_admin_clean_field_value($header, (string)($row[$header] ?? ''));
		$type = 'text';
		if (sgk_admin_is_boolean_column($header) || $header === 'abstract_later') {
			$type = 'checkbox';
		} elseif (array_key_exists($header, $selectOptions)) {
			$type = 'select';
		} elseif (in_array($header, sgk_csv_multiline_columns(), true) ||
			str_contains($value, "\n") ||
			strlen($value) > 90) {
			$type = 'textarea';
		}

		$fields[] = [
			'name'    => $header,
			'label'   => sgk_format_admin_label($header),
			'value'   => $value,
			'type'    => $type,
			'options' => $selectOptions[$header] ?? [],
		];
	}

	return $fields;
}

function sgk_admin_detail_sections($row) {
	$sections = [];

	$basic = [
		'Ime'      => sgk_admin_row_full_name($row),
		'E-mail'   => sgk_admin_preview_value('email', (string)($row['email'] ?? '')),
		'Telefon'  => sgk_admin_preview_value('phone', (string)($row['phone'] ?? '')),
		'Ustanova' => sgk_admin_preview_value('institution', (string)($row['institution'] ?? '')),
		'Naslov'   => sgk_admin_preview_value('address', (string)($row['address'] ?? '')),
		'Oddano'   => sgk_admin_preview_value('submitted_at', (string)($row['submitted_at'] ?? '')),
	];
	$basic = array_filter($basic, static fn($value) => trim($value) !== '');
	if ($basic !== []) {
		$sections[] = [
			'title' => 'Osnovni podatki',
			'items' => $basic
		];
	}

	$registration = [
		'Kotizacija'              => sgk_admin_preview_value('registration_type',
			(string)($row['registration_type'] ?? '')),
		'Način plačila'           => sgk_admin_preview_value('payment_method', (string)($row['payment_method'] ?? '')),
		'Predstavitev'            => sgk_admin_preview_value('presentation_type',
			(string)($row['presentation_type'] ?? '')),
		'Medkongresna ekskurzija' => sgk_admin_preview_value('mid_excursion', (string)($row['mid_excursion'] ?? '')),
		'Pokongresna ekskurzija'  => sgk_admin_preview_value('post_excursion', (string)($row['post_excursion'] ?? '')),
		'Foto natečaj'            => sgk_admin_preview_value('photo_contest', (string)($row['photo_contest'] ?? '')),
		'Skupaj EUR'              => sgk_admin_preview_value('total_eur', (string)($row['total_eur'] ?? '')),
	];
	$registration = array_filter($registration, static fn($value) => trim($value) !== '');
	if ($registration !== []) {
		$sections[] = [
			'title' => 'Registracija',
			'items' => $registration
		];
	}

	$abstract = [
		'Naslov prispevka'   => sgk_admin_preview_value('title', (string)($row['title'] ?? '')),
		'Avtorji'            => sgk_admin_preview_value('authors', (string)($row['authors'] ?? '')),
		'Institucije'        => sgk_admin_preview_value('institutions', (string)($row['institutions'] ?? '')),
		'Ključne besede'     => sgk_admin_preview_value('keywords', (string)($row['keywords'] ?? '')),
		'Št. ključnih besed' => sgk_admin_preview_value('keyword_count', (string)($row['keyword_count'] ?? '')),
		'Povzetek'           => sgk_admin_preview_value('abstract_text', (string)($row['abstract_text'] ?? '')),
		'Št. besed'          => sgk_admin_preview_value('abstract_word_count',
			(string)($row['abstract_word_count'] ?? '')),
	];
	$abstract = array_filter($abstract, static fn($value) => trim($value) !== '');
	if ($abstract !== []) {
		$sections[] = [
			'title' => 'Povzetek',
			'items' => $abstract
		];
	}

	$invoice = [
		'Račun enak'       => sgk_admin_preview_value('invoice_same', (string)($row['invoice_same'] ?? '')),
		'Naziv za račun'   => sgk_admin_preview_value('invoice_name', (string)($row['invoice_name'] ?? '')),
		'Naslov za račun'  => sgk_admin_preview_value('invoice_address', (string)($row['invoice_address'] ?? '')),
		'Pošta za račun'   => sgk_admin_preview_value('invoice_post', (string)($row['invoice_post'] ?? '')),
		'Država za račun'  => sgk_admin_preview_value('invoice_country', (string)($row['invoice_country'] ?? '')),
		'Davčni zavezanec' => sgk_admin_preview_value('vat_payer', (string)($row['vat_payer'] ?? '')),
		'ID za DDV'        => sgk_admin_preview_value('vat_id', (string)($row['vat_id'] ?? '')),
	];
	$invoice = array_filter($invoice, static fn($value) => trim($value) !== '');
	if ($invoice !== []) {
		$sections[] = [
			'title' => 'Račun',
			'items' => $invoice
		];
	}

	$extras = [
		'Model majice'            => sgk_admin_preview_value('shirt_gender', (string)($row['shirt_gender'] ?? '')),
		'Velikost majice'         => sgk_admin_preview_value('shirt_size', (string)($row['shirt_size'] ?? '')),
		'Vegetarijanska prehrana' => sgk_admin_preview_value('diet_vegetarian',
			(string)($row['diet_vegetarian'] ?? '')),
		'Brez laktoze'            => sgk_admin_preview_value('diet_lactose', (string)($row['diet_lactose'] ?? '')),
		'Brez glutena'            => sgk_admin_preview_value('diet_gluten', (string)($row['diet_gluten'] ?? '')),
		'Brez omejitev'           => sgk_admin_preview_value('diet_none', (string)($row['diet_none'] ?? '')),
		'Drugo prehrana'          => sgk_admin_preview_value('diet_other', (string)($row['diet_other'] ?? '')),
		'Opombe'                  => sgk_admin_preview_value('notes', (string)($row['notes'] ?? '')),
		'UPN QR'                  => sgk_admin_preview_value('upn_qr_included',
			(string)($row['upn_qr_included'] ?? '')),
	];
	$extras = array_filter($extras, static fn($value) => trim($value) !== '');
	if ($extras !== []) {
		$sections[] = [
			'title' => 'Dodatno',
			'items' => $extras
		];
	}

	return $sections;
}

function sgk_admin_download_xls($table) {
	$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
	$spreadsheet->getProperties()->setCreator('SGK')->setTitle('Prijave 7. SGK');

	$sheet = $spreadsheet->getActiveSheet();
	$sheet->setTitle('Prijave');

	$headers = $table['headers'] ?? [];
	$rows = $table['rows'] ?? [];

	foreach ($headers as $index => $header) {
		$column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
		$sheet->setCellValueExplicit($column . '1', sgk_format_admin_label($header),
			\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
	}

	foreach ($rows as $rowIndex => $row) {
		foreach ($headers as $columnIndex => $header) {
			$column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 1);
			$sheet->setCellValueExplicit($column . ($rowIndex + 2),
				sgk_admin_preview_value($header, (string)($row[$header] ?? '')),
				\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
		}
	}

	if ($headers !== []) {
		$lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
		$lastRow = max(1, count($rows) + 1);
		$sheet->freezePane('A2');
		$sheet->setAutoFilter('A1:' . $lastColumn . '1');
		$sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true);
		$sheet->getStyle('A1:' . $lastColumn . $lastRow)->getAlignment()->setWrapText(true);

		foreach (range(1, count($headers)) as $columnIndex) {
			$column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
			$sheet->getColumnDimension($column)->setAutoSize(true);
		}
	}

	header('Content-Type: application/vnd.ms-excel');
	header('Content-Disposition: attachment; filename="registracija.xls"');
	header('Cache-Control: max-age=0');

	$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet);
	$writer->save('php://output');
	exit;
}

$loginError = '';
$adminSalt = trim((string)(getenv('SGK_ADMIN_SALT') ?: ''));
$adminHash = trim((string)(getenv('SGK_ADMIN_HASH') ?: ''));
$authConfigured = $adminSalt !== '' && $adminHash !== '';
$turnstileConfigured = sgk_turnstile_is_configured();
$turnstileSiteKey = sgk_turnstile_site_key();
$isAuthenticated = !empty($_SESSION[SGK_ADMIN_SESSION_KEY]);
$adminMessage = '';
$adminMessageType = 'info';
$csvPath = __DIR__ . '/.form/submissions.csv';
$adminSortQuery = sgk_admin_sort_query($_GET);

if (!empty($_SESSION[SGK_ADMIN_FLASH_KEY]) && is_array($_SESSION[SGK_ADMIN_FLASH_KEY])) {
	$adminMessage = trim((string)($_SESSION[SGK_ADMIN_FLASH_KEY]['message'] ?? ''));
	$adminMessageType = (string)($_SESSION[SGK_ADMIN_FLASH_KEY]['type'] ?? 'info') === 'error' ? 'error': 'success';
	unset($_SESSION[SGK_ADMIN_FLASH_KEY]);
}

if (!$authConfigured) {
	unset($_SESSION[SGK_ADMIN_SESSION_KEY]);
	$isAuthenticated = false;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$action = trim((string)($_POST['action'] ?? 'login'));
	$postSortQuery = sgk_admin_sort_query($_POST);

	if ($action === 'logout') {
		unset($_SESSION[SGK_ADMIN_SESSION_KEY]);
		$isAuthenticated = false;
	} elseif (in_array($action, ['admin_update', 'admin_delete'], true)) {
		if (!$isAuthenticated) {
			$loginError = 'Seja je potekla. Prijavite se znova.';
		} else {
			$editTable = sgk_read_csv_table($csvPath);
			if ($editTable['error'] !== null) {
				$adminMessage = $editTable['error'];
				$adminMessageType = 'error';
			} else {
				$rowIndex = sgk_admin_find_row_index($editTable, $_POST['row_index'] ?? null,
					$_POST['original_submitted_at'] ?? '', $_POST['original_email'] ?? '');

				if ($rowIndex === null) {
					$adminMessage = 'Izbranega vnosa ni bilo mogoče najti.';
					$adminMessageType = 'error';
				} elseif ($action === 'admin_delete') {
					array_splice($editTable['rows'], $rowIndex, 1);
					if (sgk_admin_write_csv_table($csvPath, $editTable['headers'], $editTable['rows'])) {
						sgk_admin_set_flash('success', 'Vnos je bil izbrisan.');
						sgk_admin_redirect($postSortQuery);
					}

					$adminMessage = 'Vnosa ni bilo mogoče izbrisati.';
					$adminMessageType = 'error';
				} else {
					$fields = $_POST['fields'] ?? [];
					$fields = is_array($fields) ? $fields: [];
					$row = $editTable['rows'][$rowIndex];
					$editableHeaders = array_values(array_intersect(sgk_admin_editable_columns(), $editTable['headers']));

					foreach ($editableHeaders as $header) {
						if (sgk_admin_is_boolean_column($header) || $header === 'abstract_later') {
							$row[$header] = isset($fields[$header]) ? '1': '0';
						} else {
							$row[$header] = trim((string)($fields[$header] ?? ''));
						}
					}

					$editTable['rows'][$rowIndex] = $row;
					if (sgk_admin_write_csv_table($csvPath, $editTable['headers'], $editTable['rows'])) {
						sgk_admin_set_flash('success', 'Vnos je bil posodobljen.');
						sgk_admin_redirect($postSortQuery);
					}

					$adminMessage = 'Sprememb ni bilo mogoče shraniti.';
					$adminMessageType = 'error';
				}
			}
		}
	} else {
		$password = (string)($_POST['password'] ?? '');
		if (!$authConfigured) {
			$loginError = 'Admin prijava ni konfigurirana v .env.';
		} else {
			$turnstile = sgk_turnstile_verify($_POST['cf-turnstile-response'] ?? '', $_SERVER['REMOTE_ADDR'] ?? null);
			if (!$turnstile['success']) {
				$loginError = $turnstile['error'];
			} elseif (hash_equals($adminHash, sgk_admin_hash_password($adminSalt, $password))) {
				$_SESSION[SGK_ADMIN_SESSION_KEY] = true;
				$isAuthenticated = true;
			} else {
				unset($_SESSION[SGK_ADMIN_SESSION_KEY]);
				$isAuthenticated = false;
				$loginError = 'Napačno geslo.';
			}
		}
	}
}

if ($isAuthenticated && (($_GET['download'] ?? '') === 'xls')) {
	sgk_admin_download_xls(sgk_read_csv_table($csvPath));
}

$table = $isAuthenticated ? sgk_read_csv_table($csvPath): [
	'headers' => [],
	'rows'    => [],
	'error'   => null
];

$rowCount = count($table['rows']);
?>
<!doctype html>
<html lang="sl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin | 7. Slovenski geološki kongres</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@500;600&family=Geist:wght@300;400;500;600;700;800&display=swap"
          rel="stylesheet">
    <link rel="stylesheet" href="<?= e(sgk_asset_url('styles.css')) ?>">
	<?php if ($turnstileConfigured): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
	<?php endif; ?>
    <style>
        body.admin-page {
            height: 100vh;
            min-height: 100vh;
            overflow: hidden;
            background: radial-gradient(circle at top left, rgba(13, 90, 114, 0.13), transparent 30%),
            radial-gradient(circle at top right, rgba(183, 205, 190, 0.28), transparent 24%),
            #eef2ef;
        }

        .admin-shell {
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            width: 100%;
            height: 100dvh;
            margin: 0 auto;
            padding: 12px 0 18px;
            overflow: hidden;
        }

        .admin-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex: 0 0 auto;
            gap: 0.75rem;
            margin-bottom: 0.45rem;
            padding: 0 16px;
        }

        .admin-kicker {
            margin: 0 0 0.18rem;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .admin-title {
            margin: 0;
            font-size: clamp(1.35rem, 2vw, 1.9rem);
            line-height: 1.02;
        }

        .admin-card {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(17, 33, 40, 0.1);
            border-radius: 20px;
            box-shadow: 0 24px 55px rgba(10, 31, 38, 0.1);
            backdrop-filter: blur(8px);
        }

        .admin-login {
            width: min(440px, 100%);
            margin: 10vh auto 0;
            padding: 1.4rem;
        }

        .admin-login form {
            display: grid;
            gap: 0.9rem;
        }

        .admin-turnstile {
            width: 100%;
            min-height: 66px;
        }

        .admin-turnstile .cf-turnstile {
            width: 100%;
        }

        .admin-login input[type="password"] {
            width: 100%;
            border: 1px solid #cfd9de;
            border-radius: 10px;
            padding: 0.72rem 0.8rem;
            font: inherit;
        }

        .admin-toolbar {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .admin-stat {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0.8rem 1.2rem;
            border: 1px solid rgba(13, 90, 114, 0.18);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.78);
            color: #123b49;
            font-size: 0.95rem;
            font-weight: 700;
            line-height: 1;
        }

        .admin-table-card {
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
            padding: 0;
            border-radius: 0;
            border-left: 0;
            border-right: 0;
            box-shadow: none;
            backdrop-filter: none;
        }

        .admin-split {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(280px, 340px);
            height: 100%;
            min-height: 0;
        }

        .admin-table-wrap {
            overflow: auto;
            margin: 0;
            min-height: 0;
            border-right: 1px solid rgba(17, 33, 40, 0.08);
        }

        .admin-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 880px;
            font-size: 0.93rem;
            margin: 0;
        }

        .admin-table th,
        .admin-table td {
            padding: 0.78rem 0.8rem;
            border-bottom: 1px solid rgba(17, 33, 40, 0.08);
            text-align: left;
            vertical-align: top;
        }

        .admin-table th {
            padding: 0;
            position: sticky;
            top: 0;
            z-index: 1;
            background: #f5f9f8;
            color: #35515c;
            font-size: 0.76rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .admin-sort-button {
            appearance: none;
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.45rem;
            width: 100%;
            min-height: 44px;
            padding: 0.78rem 0.8rem;
            border: 0;
            background: transparent;
            color: inherit;
            cursor: pointer;
            font: inherit;
            letter-spacing: inherit;
            line-height: 1.1;
            text-align: left;
            text-transform: inherit;
        }

        .admin-sort-arrow {
            min-width: 0.8rem;
            color: #0d5a72;
            font-size: 0.82rem;
            line-height: 1;
            text-align: center;
        }

        .admin-table th[aria-sort="none"] .admin-sort-arrow {
            color: #6f858e;
            opacity: 0.64;
        }

        .admin-sequence-heading,
        .admin-sequence-cell {
            width: 1%;
            text-align: right;
            white-space: nowrap;
        }

        .admin-table td {
            background: rgba(255, 255, 255, 0.92);
            color: #13272f;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .admin-table tbody tr {
            cursor: pointer;
        }

        .admin-table tbody tr.is-active td {
            background: rgba(13, 90, 114, 0.10);
        }

        .admin-table tbody tr:nth-child(even) td {
            background: rgba(246, 250, 249, 0.96);
        }

        .admin-table tbody tr.is-active:nth-child(even) td {
            background: rgba(13, 90, 114, 0.12);
        }

        .admin-table td.is-empty {
            color: #7b8d95;
        }

        .admin-table td.is-boolean {
            text-align: center;
        }

        .admin-bool {
            display: inline-block;
            min-width: 1.25rem;
            font-size: 1rem;
            font-weight: 700;
            line-height: 1;
        }

        .admin-bool-yes {
            color: #1d6b3a;
        }

        .admin-bool-no {
            color: #8f2e2e;
        }

        .admin-inline-form {
            margin: 0;
            display: flex;
        }

        .admin-alert {
            margin: 0 0 1rem;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            border: 1px solid transparent;
        }

        .admin-alert.error {
            background: #fff1f1;
            border-color: #e8c2c2;
            color: #842828;
        }

        .admin-alert.info {
            background: #eef7fb;
            border-color: #c8dfe8;
            color: #204a58;
        }

        .admin-alert.success {
            background: #eef8f1;
            border-color: #b9ddc5;
            color: #1d5731;
        }

        .admin-shell > .admin-alert {
            flex: 0 0 auto;
            margin: 0 16px 0.6rem;
        }

        .admin-empty {
            padding: 1.4rem 1.2rem;
            color: #43606a;
        }

        .admin-preview {
            padding: 0.75rem 0.8rem 0.9rem;
            overflow: auto;
            min-height: 0;
            background: radial-gradient(circle at top right, rgba(13, 90, 114, 0.06), transparent 28%),
            rgba(255, 253, 248, 0.92);
        }

        .admin-preview-header {
            margin-bottom: 0.65rem;
            padding-bottom: 0.55rem;
            border-bottom: 1px solid rgba(17, 33, 40, 0.08);
        }

        .admin-preview-kicker {
            margin: 0 0 0.2rem;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.66rem;
            font-weight: 700;
        }

        .admin-preview-title {
            margin: 0;
            font-size: 1.02rem;
            line-height: 1.12;
            color: #13272f;
        }

        .admin-preview-subtitle {
            margin: 0.2rem 0 0;
            color: #4a6169;
            font-size: 0.82rem;
        }

        .admin-preview-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
            margin-top: 0.6rem;
        }

        .admin-action-button {
            appearance: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0.42rem 0.72rem;
            border: 1px solid rgba(13, 90, 114, 0.22);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.9);
            color: #123b49;
            cursor: pointer;
            font: inherit;
            font-size: 0.78rem;
            font-weight: 700;
            line-height: 1;
        }

        .admin-action-button.danger {
            border-color: rgba(143, 46, 46, 0.25);
            color: #842828;
        }

        .admin-preview-section {
            padding: 10px;
        }

        .admin-preview-section + .admin-preview-section {
            margin-top: 0.2rem;
        }

        .admin-preview-section-title {
            margin: 0 0 0.12rem;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.68rem;
            font-weight: 700;
        }

        .admin-preview-list {
            display: grid;
            gap: 1px;
            background: rgba(17, 33, 40, 0.08);
            border: 1px solid rgba(17, 33, 40, 0.08);
            border-radius: 8px;
            overflow: hidden;
        }

        .admin-preview-row {
            display: grid;
            grid-template-columns: 110px minmax(0, 1fr);
            gap: 0.45rem;
            align-items: start;
            padding: 0.34rem 0.45rem;
            background: rgba(255, 255, 255, 0.9);
        }

        .admin-preview-label {
            color: #45606a;
            font-size: 0.63rem;
            letter-spacing: 0.07em;
            text-transform: uppercase;
            font-weight: 700;
            line-height: 1.25;
        }

        .admin-preview-value {
            color: #13272f;
            font-size: 0.84rem;
            line-height: 1.28;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .admin-dialog {
            width: min(780px, calc(100vw - 24px));
            max-height: calc(100dvh - 32px);
            padding: 0;
            border: 0;
            border-radius: 12px;
            box-shadow: 0 24px 70px rgba(10, 31, 38, 0.26);
            color: #13272f;
        }

        .admin-dialog::backdrop {
            background: rgba(10, 31, 38, 0.42);
        }

        .admin-dialog form {
            display: flex;
            flex-direction: column;
            max-height: calc(100dvh - 32px);
        }

        .admin-dialog-header,
        .admin-dialog-footer {
            flex: 0 0 auto;
            padding: 0.9rem 1rem;
        }

        .admin-dialog-header {
            border-bottom: 1px solid rgba(17, 33, 40, 0.08);
        }

        .admin-dialog-title {
            margin: 0;
            font-size: 1.05rem;
            line-height: 1.15;
        }

        .admin-dialog-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.6rem;
            border-top: 1px solid rgba(17, 33, 40, 0.08);
        }

        .admin-edit-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
            min-height: 0;
            padding: 1rem;
            overflow: auto;
        }

        .admin-edit-field {
            display: grid;
            gap: 0.28rem;
        }

        .admin-edit-field.is-checkbox {
            align-content: end;
        }

        .admin-edit-field span {
            color: #45606a;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.07em;
            line-height: 1.2;
            text-transform: uppercase;
        }

        .admin-edit-field input,
        .admin-edit-field select,
        .admin-edit-field textarea {
            width: 100%;
            border: 1px solid #cfd9de;
            border-radius: 8px;
            padding: 0.62rem 0.68rem;
            font: inherit;
            font-size: 0.9rem;
        }

        .admin-edit-check {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            min-height: 43px;
            padding: 0.62rem 0.68rem;
            border: 1px solid #cfd9de;
            border-radius: 8px;
            background: #fff;
            font-size: 0.9rem;
        }

        .admin-edit-check input {
            width: auto;
            margin: 0;
            padding: 0;
        }

        .admin-edit-field textarea {
            min-height: 96px;
            resize: vertical;
        }

        @media (max-width: 760px) {
            .admin-shell {
                padding-top: 14px;
            }

            .admin-bar {
                flex-direction: column;
                align-items: flex-start;
            }

            .admin-split {
                grid-template-columns: 1fr;
                grid-template-rows: minmax(0, 48%) minmax(0, 52%);
            }

            .admin-table-wrap {
                border-right: 0;
                border-bottom: 1px solid rgba(17, 33, 40, 0.08);
            }

            .admin-preview-row {
                grid-template-columns: 1fr;
                gap: 0.12rem;
            }

            .admin-edit-fields {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="admin-page">
<main class="admin-shell">
	<?php if (!$isAuthenticated): ?>
        <section class="admin-card admin-login">
            <p class="admin-kicker">Admin</p>
            <h1 class="admin-title">Prijave</h1>
            <p class="admin-subtitle">Dostop do administrativnega pregleda prijav je zaščiten z geslom.</p>
			<?php if ($loginError !== ''): ?>
                <div class="admin-alert error"><?= e($loginError) ?></div>
			<?php endif; ?>
			<?php if (!$turnstileConfigured): ?>
                <div class="admin-alert info">Turnstile ni konfiguriran v <code>.env</code>.</div>
			<?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <label>
                    <input type="password" name="password" autocomplete="current-password" required placeholder="Geslo">
                </label>
				<?php if ($turnstileConfigured): ?>
                    <div class="admin-turnstile">
                        <div class="cf-turnstile" data-sitekey="<?= e($turnstileSiteKey) ?>" data-theme="light"
                             data-size="flexible"></div>
                    </div>
				<?php endif; ?>
                <button type="submit" class="btn btn-primary">Prijava</button>
            </form>
        </section>
	<?php else: ?>
        <section class="admin-bar">
            <div>
                <p class="admin-kicker">Admin</p>
                <h1 class="admin-title">Pregled vnosov</h1>
            </div>
            <div class="admin-toolbar">
                <span class="admin-stat"><?= e((string)$rowCount) ?> prijav</span>
                <a class="btn btn-secondary" href="/">Nazaj na domačo stran</a>
                <a class="btn btn-secondary" href="/admin?download=xls<?= $adminSortQuery !== '' ? '&' . e(ltrim($adminSortQuery, '?')): '' ?>">Prenesi XLS</a>
                <form method="post" class="admin-inline-form">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="btn btn-secondary">Odjava</button>
                </form>
            </div>
        </section>

		<?php if ($adminMessage !== ''): ?>
            <div class="admin-alert <?= $adminMessageType === 'error' ? 'error': 'success' ?>"><?= e($adminMessage) ?></div>
		<?php endif; ?>

        <section class="admin-card admin-table-card">

			<?php if ($table['error'] !== null): ?>
                <div class="admin-alert info"><?= e($table['error']) ?></div>
			<?php elseif ($table['headers'] === []): ?>
                <div class="admin-empty">V datoteki trenutno ni podatkov.</div>
			<?php else: ?>
                <div class="admin-split">
                    <div class="admin-table-wrap">
                        <table class="admin-table" aria-label="Seznam prijav">
                            <thead>
                            <tr>
                                <th aria-sort="none" class="admin-sequence-heading">
                                    <button type="button" class="admin-sort-button" data-sort-key="sequence">
                                        <span>#</span><span class="admin-sort-arrow" aria-hidden="true">↕</span>
                                    </button>
                                </th>
                                <th aria-sort="none">
                                    <button type="button" class="admin-sort-button" data-sort-key="submitted_at">
                                        <span>Oddano</span><span class="admin-sort-arrow" aria-hidden="true">↕</span>
                                    </button>
                                </th>
                                <th aria-sort="none">
                                    <button type="button" class="admin-sort-button" data-sort-key="name">
                                        <span>Ime</span><span class="admin-sort-arrow" aria-hidden="true">↕</span>
                                    </button>
                                </th>
                                <th aria-sort="none">
                                    <button type="button" class="admin-sort-button" data-sort-key="institution">
                                        <span>Ustanova</span><span class="admin-sort-arrow" aria-hidden="true">↕</span>
                                    </button>
                                </th>
                                <th aria-sort="none">
                                    <button type="button" class="admin-sort-button" data-sort-key="payment_method">
                                        <span>Način plačila</span><span class="admin-sort-arrow" aria-hidden="true">↕</span>
                                    </button>
                                </th>
                                <th aria-sort="none">
                                    <button type="button" class="admin-sort-button" data-sort-key="contact">
                                        <span>Kontakt</span><span class="admin-sort-arrow" aria-hidden="true">↕</span>
                                    </button>
                                </th>
                                <th aria-sort="none">
                                    <button type="button" class="admin-sort-button" data-sort-key="presentation_type">
                                        <span>Predstavitev</span><span class="admin-sort-arrow" aria-hidden="true">↕</span>
                                    </button>
                                </th>
                            </tr>
                            </thead>
                            <tbody>
							<?php foreach ($table['rows'] as $index => $row): ?>
								<?php
								$contactSummary = trim((sgk_admin_summary_value($row, 'email') ?: '') .
									((sgk_admin_summary_value($row, 'phone') ?: '') !== '' ?
										' / ' . sgk_admin_summary_value($row, 'phone'): ''));
								$detailPayload = [
									'rowIndex'            => $index,
									'originalSubmittedAt' => trim((string)($row['submitted_at'] ?? '')),
									'originalEmail'       => trim((string)($row['email'] ?? '')),
									'name'                => sgk_admin_row_full_name($row),
									'subtitle'            => trim((string)($row['email'] ?? '')),
									'fields'              => sgk_admin_edit_fields($table['headers'], $row),
									'sections'            => sgk_admin_detail_sections($row),
								];
								?>
                                <tr class="<?= $index === 0 ? 'is-active': '' ?>"
                                    data-original-index="<?= e((string)$index) ?>"
                                    data-sort-submitted_at="<?= e((string)($row['submitted_at'] ?? '')) ?>"
                                    data-sort-name="<?= e(sgk_admin_summary_value($row, 'name')) ?>"
                                    data-sort-institution="<?= e(sgk_admin_summary_value($row, 'institution')) ?>"
                                    data-sort-payment_method="<?= e(sgk_admin_summary_value($row, 'payment_method')) ?>"
                                    data-sort-contact="<?= e($contactSummary) ?>"
                                    data-sort-presentation_type="<?= e(sgk_admin_summary_value($row, 'presentation_type')) ?>"
                                    data-preview='<?= e(json_encode($detailPayload,
									    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?>'>
                                    <td class="admin-sequence-cell"><?= e((string)($index + 1)) ?></td>
                                    <td><?= e(sgk_admin_summary_value($row, 'submitted_at') ?: '—') ?></td>
                                    <td><?= e(sgk_admin_summary_value($row, 'name') ?: '—') ?></td>
                                    <td><?= e(sgk_admin_summary_value($row, 'institution') ?: '—') ?></td>
                                    <td><?= e(sgk_admin_summary_value($row, 'payment_method') ?: '—') ?></td>
                                    <td><?= e($contactSummary ?: '—') ?></td>
                                    <td><?= e(sgk_admin_summary_value($row, 'presentation_type') ?: '—') ?></td>
                                </tr>
							<?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <aside class="admin-preview" id="admin-preview" aria-live="polite"></aside>
                </div>
			<?php endif; ?>
        </section>
		<?php if ($table['error'] === null && $table['headers'] !== []): ?>
            <form method="post" id="admin-delete-form" hidden>
                <input type="hidden" name="action" value="admin_delete">
                <input type="hidden" name="row_index">
                <input type="hidden" name="original_submitted_at">
                <input type="hidden" name="original_email">
                <input type="hidden" name="sort">
                <input type="hidden" name="dir">
            </form>

            <dialog class="admin-dialog" id="admin-edit-dialog">
                <form method="post" id="admin-edit-form">
                    <input type="hidden" name="action" value="admin_update">
                    <input type="hidden" name="row_index">
                    <input type="hidden" name="original_submitted_at">
                    <input type="hidden" name="original_email">
                    <input type="hidden" name="sort">
                    <input type="hidden" name="dir">
                    <header class="admin-dialog-header">
                        <h2 class="admin-dialog-title">Uredi prijavo</h2>
                    </header>
                    <div class="admin-edit-fields" id="admin-edit-fields"></div>
                    <footer class="admin-dialog-footer">
                        <button type="button" class="btn btn-secondary" data-admin-edit-close>Prekliči</button>
                        <button type="submit" class="btn btn-primary">Shrani</button>
                    </footer>
                </form>
            </dialog>
		<?php endif; ?>
	<?php endif; ?>
</main>
<?php if ($isAuthenticated && $table['error'] === null && $table['headers'] !== []): ?>
    <script>
        (function () {
            const rows = Array.from(document.querySelectorAll('.admin-table tbody tr[data-preview]'));
            const tbody = document.querySelector('.admin-table tbody');
            const preview = document.getElementById('admin-preview');
            const sortButtons = Array.from(document.querySelectorAll('.admin-sort-button[data-sort-key]'));
            const editDialog = document.getElementById('admin-edit-dialog');
            const editForm = document.getElementById('admin-edit-form');
            const editFields = document.getElementById('admin-edit-fields');
            const deleteForm = document.getElementById('admin-delete-form');
            const sortParams = new URLSearchParams(window.location.search);
            const allowedSortKeys = new Set(sortButtons.map(function (button) {
                return button.dataset.sortKey || '';
            }));
            let currentPayload = null;
            let activeSortKey = allowedSortKeys.has(sortParams.get('sort')) ? sortParams.get('sort') : null;
            let activeSortDirection = sortParams.get('dir') === 'desc' ? 'desc' : 'asc';
            if (!rows.length || !preview) return;

            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function readPayload(row) {
                try {
                    return JSON.parse(row.dataset.preview || '{}');
                } catch (error) {
                    return {};
                }
            }

            function setFormIdentity(form, payload) {
                if (!form || !payload) return;
                form.querySelector('[name="row_index"]').value = payload.rowIndex ?? '';
                form.querySelector('[name="original_submitted_at"]').value = payload.originalSubmittedAt || '';
                form.querySelector('[name="original_email"]').value = payload.originalEmail || '';
                form.querySelector('[name="sort"]').value = activeSortKey || '';
                form.querySelector('[name="dir"]').value = activeSortKey ? activeSortDirection : '';
            }

            function renderPreview(payload) {
                currentPayload = payload || {};
                const name = payload && payload.name ? payload.name : 'Izbrana prijava';
                const subtitle = payload && payload.subtitle ? payload.subtitle : '';
                const sections = payload && Array.isArray(payload.sections) ? payload.sections : [];

                let html = '';
                html += '<header class="admin-preview-header">';
                html += '<p class="admin-preview-kicker">Predogled prijave</p>';
                html += '<h2 class="admin-preview-title">' + escapeHtml(name) + '</h2>';
                if (subtitle) {
                    html += '<p class="admin-preview-subtitle">' + escapeHtml(subtitle) + '</p>';
                }
                html += '<div class="admin-preview-actions">';
                html += '<button type="button" class="admin-action-button" data-admin-action="edit">Uredi</button>';
                html += '<button type="button" class="admin-action-button danger" data-admin-action="delete">Izbriši</button>';
                html += '</div>';
                html += '</header>';

                sections.forEach(function (section) {
                    const items = Array.isArray(section.items)
                        ? section.items
                        : Object.entries(section.items || {});

                    if (!items.length) return;

                    html += '<section class="admin-preview-section">';
                    html += '<h3 class="admin-preview-section-title">' + escapeHtml(section.title || '') + '</h3>';
                    html += '<div class="admin-preview-list">';

                    items.forEach(function (item) {
                        const label = Array.isArray(item) ? item[0] : '';
                        const value = Array.isArray(item) ? item[1] : '';
                        if (!String(value || '').trim()) return;

                        html += '<div class="admin-preview-row">';
                        html += '<div class="admin-preview-label">' + escapeHtml(label) + '</div>';
                        html += '<div class="admin-preview-value">' + escapeHtml(value) + '</div>';
                        html += '</div>';
                    });

                    html += '</div>';
                    html += '</section>';
                });

                preview.innerHTML = html;
            }

            function openEditDialog(payload) {
                if (!editDialog || !editForm || !editFields || !payload) return;

                setFormIdentity(editForm, payload);
                editFields.replaceChildren();

                (Array.isArray(payload.fields) ? payload.fields : []).forEach(function (field) {
                    const label = document.createElement('label');
                    const labelText = document.createElement('span');
                    let control;

                    label.className = 'admin-edit-field';
                    labelText.textContent = field.label || field.name || '';

                    if (field.type === 'checkbox') {
                        const checkWrap = document.createElement('span');
                        const checkText = document.createElement('span');
                        control = document.createElement('input');

                        label.classList.add('is-checkbox');
                        control.type = 'checkbox';
                        control.name = 'fields[' + (field.name || '') + ']';
                        control.value = '1';
                        control.checked = ['1', 'true', 'yes', 'da'].includes(String(field.value || '').toLowerCase());
                        checkWrap.className = 'admin-edit-check';
                        checkText.textContent = 'Da';
                        checkWrap.appendChild(control);
                        checkWrap.appendChild(checkText);
                        label.appendChild(labelText);
                        label.appendChild(checkWrap);
                        editFields.appendChild(label);
                        return;
                    }

                    if (field.type === 'select') {
                        control = document.createElement('select');
                        control.name = 'fields[' + (field.name || '') + ']';
                        Object.entries(field.options || {}).forEach(function (option) {
                            const optionElement = document.createElement('option');
                            optionElement.value = option[0];
                            optionElement.textContent = option[1];
                            optionElement.selected = option[0] === (field.value || '');
                            control.appendChild(optionElement);
                        });
                    } else if (field.type === 'textarea') {
                        control = document.createElement('textarea');
                        control.name = 'fields[' + (field.name || '') + ']';
                        control.rows = 4;
                        control.value = field.value || '';
                    } else {
                        control = document.createElement('input');
                        control.name = 'fields[' + (field.name || '') + ']';
                        control.type = 'text';
                        control.value = field.value || '';
                    }

                    label.appendChild(labelText);
                    label.appendChild(control);
                    editFields.appendChild(label);
                });

                if (typeof editDialog.showModal === 'function') {
                    editDialog.showModal();
                } else {
                    editDialog.setAttribute('open', '');
                }
            }

            function closeEditDialog() {
                if (!editDialog) return;
                if (typeof editDialog.close === 'function') {
                    editDialog.close();
                } else {
                    editDialog.removeAttribute('open');
                }
            }

            function activateRow(row) {
                rows.forEach(function (item) {
                    item.classList.remove('is-active');
                });
                row.classList.add('is-active');
                renderPreview(readPayload(row));
            }

            function sortValue(row, key) {
                const value = key === 'sequence'
                    ? (row.getAttribute('data-sort-submitted_at') || '')
                    : (row.getAttribute('data-sort-' + key) || '');
                if (key === 'submitted_at' || key === 'sequence') {
                    const timestamp = Date.parse(value);
                    return Number.isNaN(timestamp) ? Number.NEGATIVE_INFINITY : timestamp;
                }

                return value.toLocaleLowerCase('sl-SI');
            }

            function updateSequenceCells() {
                Array.from(tbody.querySelectorAll('tr[data-preview]')).forEach(function (row, index) {
                    const cell = row.querySelector('.admin-sequence-cell');
                    if (cell) {
                        cell.textContent = String(index + 1);
                    }
                });
            }

            function updateSortHeaders() {
                sortButtons.forEach(function (button) {
                    const key = button.dataset.sortKey || '';
                    const th = button.closest('th');
                    const arrow = button.querySelector('.admin-sort-arrow');
                    const active = key === activeSortKey;

                    if (th) {
                        th.setAttribute('aria-sort', active ?
                            (activeSortDirection === 'asc' ? 'ascending' : 'descending') : 'none');
                    }

                    if (arrow) {
                        arrow.textContent = active ? (activeSortDirection === 'asc' ? '↑' : '↓') : '↕';
                    }
                });
            }

            function syncSortUrl() {
                const params = new URLSearchParams(window.location.search);
                params.delete('download');

                if (activeSortKey) {
                    params.set('sort', activeSortKey);
                    params.set('dir', activeSortDirection);
                } else {
                    params.delete('sort');
                    params.delete('dir');
                }

                const query = params.toString();
                const nextUrl = window.location.pathname + (query ? '?' + query : '');
                window.history.replaceState(null, '', nextUrl);
            }

            function sortRows(key, direction, updateUrl) {
                if (!tbody) return;
                if (!allowedSortKeys.has(key)) return;
                activeSortDirection = direction || (activeSortKey === key && activeSortDirection === 'asc' ? 'desc' : 'asc');
                activeSortKey = key;
                updateSortHeaders();
                if (updateUrl) {
                    syncSortUrl();
                }

                rows.slice().sort(function (a, b) {
                    const aValue = sortValue(a, key);
                    const bValue = sortValue(b, key);
                    let result;

                    if (typeof aValue === 'number' && typeof bValue === 'number') {
                        result = aValue - bValue;
                    } else {
                        result = String(aValue).localeCompare(String(bValue), 'sl', {
                            numeric: true,
                            sensitivity: 'base'
                        });
                    }

                    if (result === 0) {
                        result = Number(a.dataset.originalIndex || 0) - Number(b.dataset.originalIndex || 0);
                    }

                    return activeSortDirection === 'asc' ? result : -result;
                }).forEach(function (row) {
                    tbody.appendChild(row);
                });
                updateSequenceCells();
            }

            rows.forEach(function (row) {
                row.addEventListener('click', function () {
                    activateRow(row);
                });
            });

            sortButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    sortRows(button.dataset.sortKey || '', null, true);
                });
            });

            preview.addEventListener('click', function (event) {
                const actionButton = event.target.closest('[data-admin-action]');
                if (!actionButton || !currentPayload) return;

                if (actionButton.dataset.adminAction === 'edit') {
                    openEditDialog(currentPayload);
                }

                if (actionButton.dataset.adminAction === 'delete' && deleteForm) {
                    if (!window.confirm('Izbrišem izbrani vnos?')) return;
                    setFormIdentity(deleteForm, currentPayload);
                    deleteForm.submit();
                }
            });

            document.querySelectorAll('[data-admin-edit-close]').forEach(function (button) {
                button.addEventListener('click', closeEditDialog);
            });

            updateSortHeaders();
            if (activeSortKey) {
                sortRows(activeSortKey, activeSortDirection, false);
            }

            (tbody ? tbody.querySelector('tr[data-preview]') : rows[0]).click();
        })();
    </script>
<?php endif; ?>
</body>
</html>
