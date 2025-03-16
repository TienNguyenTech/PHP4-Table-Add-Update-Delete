<?php
global $dbh;
require_once("connection.php");

// Test if student id has been provided. If not, take user back to the listing page
if (empty($_GET['id'])) {
    header('Location: index.php');
}

// If the user has completed the form:
if ($_SERVER['REQUEST_METHOD'] == 'POST'):
    try {
        // Start a transaction so we can roll back if anything go south
        $dbh->beginTransaction();

        // Update the record based on the form received
        $query = "UPDATE `students` SET 
                  `first_name`    = :first_name, 
                  `surname`       = :surname,
                  `home_address`  = :home_address,
                  `parent_phone`  = :parent_phone,
                  `parent_email`  = :parent_email,
                  `date_of_birth` = :date_of_birth,
                  `subscribed`    = :subscribed,
                  `school_id`     = :school_id
            WHERE `id`            = :id";
        $stmt = $dbh->prepare($query);

        // Execute the query
        $stmt->execute([
            'first_name' => $_POST['first_name'],
            'surname' => $_POST['surname'],
            // Home address needs to be set to null when left empty
            'home_address' => $_POST['home_address'] ? $_POST['home_address'] : null,
            'parent_phone' => $_POST['parent_phone'],
            'parent_email' => $_POST['parent_email'],
            // Date of birth needs to be set to null when left empty
            'date_of_birth' => $_POST['date_of_birth'] ? $_POST['date_of_birth'] : null,
            // Subscribed field needs to be set to correct value based on whether the box has been ticked
            // Note when input checkbox is not being ticked, you don't even get the key, so
            // we'll use a null coalescing operator to default an unchecked box to 0 (false)
            'subscribed' => $_POST['subscribed'] ?? '0',
            // Note school id is an integer - and you should cast the value to integer when needed
            'school_id' => $_POST['school_id'] ? intval($_POST['school_id']) : null,
            'id' => $_GET['id']
        ]);

        // Then, clean up all records in the bridging table with the current student id
        $cleanup_course_student_stmt = $dbh->prepare("DELETE FROM `courses_students` WHERE `student_id` = :student_id");
        $cleanup_course_student_stmt->execute(['student_id' => $_GET['id']]);

        // And use the primary key to re-insert relationship pairs into bridging table
        $insert_course_student_query = "INSERT INTO `courses_students` (`student_id`, `course_id`) VALUES (:student_id, :course_id)";
        $insert_course_student_stmt = $dbh->prepare($insert_course_student_query);

        // There will be multiple id pairs, let's insert them one by one
        foreach ($_POST['course_ids'] as $course_id) {
            $insert_course_student_stmt->execute([
                'student_id' => $_GET['id'],
                'course_id' => $course_id
            ]);
        }

        // And commit the changes if everything goes well
        $dbh->commit();

        // And send the user back to where we were
        header('Location: index.php');
    } catch (PDOException $e) {
        // If we are here, it means something has gone south - undo all queries
        if ($dbh->inTransaction()) $dbh->rollBack();
        displayPDOError($e);
    }
else:
    // Otherwise read the record from database with ID and prefill the form
    $stmt = $dbh->prepare("SELECT * FROM `students` WHERE `id` = :id");
    $stmt->execute(['id' => $_GET['id']]);

    // Query schools for the dropdown list
    $schools_stmt = $dbh->prepare('SELECT * FROM `schools` ORDER BY `name` ASC');
    $schools_stmt->execute();

    // Query courses for the multiple checkbox list
    $courses_stmt = $dbh->prepare('SELECT * FROM `courses` ORDER BY `name` ASC');
    $courses_stmt->execute();

    // Query courses which the current student has enrolled in
    $courses_enrolled_stmt = $dbh->prepare('SELECT `course_id` FROM `courses_students` WHERE `student_id` = :student_id');
    $courses_enrolled_stmt->execute(['student_id' => $_GET['id']]);
    $courses_enrolled = $courses_enrolled_stmt->fetchAll(PDO::FETCH_FUNC, function ($course_id) {
        return $course_id;
    });

    if ($stmt->rowCount() == 1 && $row = $stmt->fetchObject()): ?>
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <title>Update Students (<?= $row->first_name ?> <?= $row->surname ?>)</title>
        </head>
        <body>
        <h1>Update student #<?= $row->id ?> (<?= $row->first_name ?> <?= $row->surname ?>)</h1>

        <form method="post">
            <label for="first-name">First name:</label><br>
            <input type="text" maxlength="128" id="first-name" name="first_name" value="<?= $row->first_name ?>" required><br>

            <label for="surname">Surname:</label><br>
            <input type="text" maxlength="128" id="surname" name="surname" value="<?= $row->surname ?>" required><br>

            <label for="home-address">Home address:</label><br>
            <input type="text" maxlength="65535" id="home-address" name="home_address" value="<?= $row->home_address ?>"><br>

            <label for="parent-phone">Parent Phone/Mobile:</label><br>
            <input type="text" maxlength="10" pattern="0[1-9][0-9]{8}" title="Australian phone number, such as '0412345678'" id="parent-phone" name="parent_phone" value="<?= $row->parent_phone ?>" required><br>

            <label for="parent-email">Parent Email:</label><br>
            <input type="email" id="parent-email" name="parent_email" value="<?= $row->parent_email ?>" required><br>

            <label for="dob">Date of birth:</label><br>
            <input type="date" id="dob" name="date_of_birth" value="<?= $row->date_of_birth ?>"><br>

            <label for="school">School:</label><br>
            <select id="school" name="school_id">
                <!-- Don't forget to add an option with empty value so you can choose no school for a student-->
                <option value="">== No school ==</option>
                <?php while ($school_row = $schools_stmt->fetchObject()): ?>
                    <!-- Since we're on an update page, you'll need to pre-select school option accordingly as well-->
                    <option value="<?= $school_row->id ?>" <?= (!empty($row->school_id) && $row->school_id == $school_row->id) ? "selected" : "" ?>><?= $school_row->name ?></option>
                <?php endwhile; ?>
            </select><br>

            <label>Courses enrolled:</label><br>
            <?php while ($course_row = $courses_stmt->fetchObject()): ?>
                <input type="checkbox" id="courses[<?= $course_row->id ?>]" name="course_ids[]" value="<?= $course_row->id ?>" <?= in_array($course_row->id, $courses_enrolled) ? "checked" : "" ?>>
                <label for="courses[<?= $course_row->id ?>]"> <?= $course_row->name ?> ($<?= $course_row->fees ?>)</label><br>
            <?php endwhile; ?>

            <br>
            <input type="checkbox" id="subscribe" name="subscribed" value="1" <?= $row->subscribed ? "checked" : "" ?>>
            <label for="subscribe"> Subscribe to newsletters</label><br><br>

            <input type="submit" value="Update">
        </form>
        </body>
        </html>
    <?php else:
        // If the record is not found (rowcount is not 1), send user back to listing page (invalid ID)
        header('Location: index.php');
    endif;
endif; ?>