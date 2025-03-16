<?php
global $dbh;
require_once("connection.php");

// If the user has completed the form:
if ($_SERVER['REQUEST_METHOD'] == 'POST'):
    try {
        // Start a transaction so we can roll back if anything go south
        $dbh->beginTransaction();

        // Add new student based on the form received
        $insert_student_query = "INSERT INTO `students`
            (`first_name`, `surname`, `home_address`, `parent_phone`, `parent_email`, `date_of_birth`, `subscribed`, `school_id`) VALUES 
            (:first_name,  :surname,  :home_address,  :parent_phone,  :parent_email,  :date_of_birth,  :subscribed,  :school_id)";
        $insert_student_stmt = $dbh->prepare($insert_student_query);

        // Execute the query
        $insert_student_stmt->execute([
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
        ]);

        // Now we can retrieve the primary key (id) of the newly created student
        $new_student_id = $dbh->lastInsertId();

        // And use the primary key to insert relationship pairs into bridging table
        $insert_course_student_stmt = $dbh->prepare("INSERT INTO `courses_students` (`student_id`, `course_id`) VALUES (:student_id, :course_id)");

        // There will be multiple id pairs, let's insert them one by one
        foreach ($_POST['course_ids'] as $course_id) {
            $insert_course_student_stmt->execute([
                'student_id' => $new_student_id,
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
    // Query schools for the dropdown list
    $schools_stmt = $dbh->prepare('SELECT * FROM `schools` ORDER BY `name` ASC');
    $schools_stmt->execute();

    // Query courses for the multiple select list
    $courses_stmt = $dbh->prepare('SELECT * FROM `courses` ORDER BY `name` ASC');
    $courses_stmt->execute();
    ?>

    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>List Students</title>
    </head>
    <body>
    <h1>Add new student</h1>

    <form method="post">
        <label for="first-name">First name:</label><br>
        <input type="text" maxlength="128" id="first-name" name="first_name" required><br>

        <label for="surname">Surname:</label><br>
        <input type="text" maxlength="128" id="surname" name="surname" required><br>

        <label for="home-address">Home address:</label><br>
        <input type="text" maxlength="65535" id="home-address" name="home_address"><br>

        <label for="parent-phone">Parent Phone/Mobile:</label><br>
        <input type="text" maxlength="10" pattern="0[1-9][0-9]{8}" title="Australian phone number, such as '0412345678'" id="parent-phone" name="parent_phone" required><br>

        <label for="parent-email">Parent Email:</label><br>
        <input type="email" id="parent-email" name="parent_email" required><br>

        <label for="dob">Date of birth:</label><br>
        <input type="date" id="dob" name="date_of_birth"><br>

        <label for="school">School:</label><br>
        <select id="school" name="school_id">
            <!-- Don't forget to add an option with empty value so you can choose no school for a student-->
            <option value="">== No school ==</option>
            <?php while ($school_row = $schools_stmt->fetchObject()): ?>
                <option value="<?= $school_row->id ?>"><?= $school_row->name ?></option>
            <?php endwhile; ?>
        </select><br>

        <label for="courses">Courses to enrol:</label><br>
        <select id="courses" name="course_ids[]" multiple size="10">
            <?php while ($course_row = $courses_stmt->fetchObject()): ?>
                <option value="<?= $course_row->id ?>"><?= $course_row->name ?> ($<?= $course_row->fees ?>)</option>
            <?php endwhile; ?>
        </select><br><br>

        <!-- Since the subscribed attribute in the database takes 1 for true and 0 for false,
        we'll set the value of the checkbox to 1 by default. And don't forget the "value"
        attribute only sets the data being sent, it does NOT check the box for you! -->
        <input type="checkbox" id="subscribe" name="subscribed" value="1" checked>
        <label for="subscribe"> Subscribe to newsletters</label><br><br>

        <input type="submit" value="Add">
    </form>
    </body>
    </html>
<?php endif; ?>